<?php
/**
 * af_enhance_content - Comprehensive article content enhancement for RSS feeds
 *
 * This plugin improves article quality for TT-RSS beyond what feeds provide
 * on their own:
 * 1. Inline image enhancement (srcset, lazy loading)
 * 2. Enclosure MIME type fixing
 * 3. Open Graph metadata extraction
 * 4. Enclosure URL upgrading from article pages
 * 5. Article markup repair (escaped anchor tags, ambiguous quoted attributes)
 * 6. Content backfill for articles with little/no body text (og:description,
 *    falling back to extracted article body text) and little/no hero image
 *    (og:image, falling back to an image extracted from the article page)
 * 7. Site-specific handling for feeds whose generic og:* metadata is
 *    unhelpful (currently: Kagi News)
 *
 * Features:
 * - Rewrite img src to use highest resolution from srcset
 * - Convert data-src to src for lazy-loaded images
 * - Remove loading="lazy" attributes
 * - Fix empty enclosure content_type
 * - Fetch article pages and extract OG metadata
 * - Add og:image as enclosure, falling back to an image extracted from the
 *   article page when there's no og:image either
 * - Set author from og:article:author
 * - Backfill missing/short content from og:description, or extracted
 *   article body text when no og:description is available
 * - Upgrade low-resolution enclosure URLs by fetching article page and extracting high-res URLs from srcset
 * - Decode double-escaped anchor tags some CMSes emit into their RSS feeds
 * - Re-quote single-quoted HTML attributes containing an apostrophe, which
 *   otherwise breaks TT-RSS core's strip_tags()-based excerpt computation
 * - Kagi News (kite.kagi.com): fetch a source article from Kagi's own
 *   "Sources:" list instead of Kagi's page, whose og:image is always the
 *   same generic site banner regardless of story
 * - Set the ttrss_feeds.cache_images database default so new feeds get
 *   image caching on by default, plus a one-time bulk-apply button for
 *   existing feeds (replaces the old standalone enable-global-cache-settings.sql)
 *
 * Installation:
 * 1. Copy this directory to plugins.local/af_enhance_content/
 * 2. Enable the plugin in Preferences -> Plugins
 * 3. Configure in Preferences -> Feeds -> Content Enhancement
 *
 * Version: 2.1
 * Author: jayemar
 */
class Af_Enhance_Content extends Plugin {

    private $host;

    // Prepended to content backfilled via extract_body_summary() (as
    // opposed to a publisher-provided og:description), so it's visually
    // distinguishable as an auto-assembled summary in the article reader.
    private const EXTRACTED_SUMMARY_ICON = '✨';

    // Cap on how many Kagi News "Sources:" links to try fetching before
    // giving up on finding a real hero image - bounds worst-case fetch
    // count per article rather than working through the whole list.
    private const KAGI_SOURCE_FETCH_LIMIT = 3;

    // Appended to the author name when it came from twitter:creator/
    // twitter:site rather than a real og:article:author byline, so it's
    // visually clear this is a handle (possibly the publication's own
    // account, not necessarily the individual writer) rather than a name.
    private const TWITTER_AUTHOR_ICON = '🐦';

    public function about() {
        return array(
            2.1,
            "Comprehensive article content enhancement: images, Open Graph metadata, markup repair, and content backfill for sparse articles",
            "jayemar"
        );
    }

    public function init($host) {
        $this->host = $host;
        $host->add_hook($host::HOOK_ARTICLE_FILTER, $this);
        $host->add_hook($host::HOOK_RENDER_ARTICLE_API, $this);
        $host->add_hook($host::HOOK_QUERY_HEADLINES, $this);
        $host->add_hook($host::HOOK_PREFS_TAB, $this);

        // Diagnostic: Confirm plugin initialization (non-verbose for visibility)
        Debug::log("AF_ENHANCE_CONTENT: Plugin initialized successfully");
    }

    // =====================================================================
    // CONFIGURATION UI
    // =====================================================================

    // Reads the live database column default rather than plugin storage,
    // since the actual authoritative state is the ttrss_feeds schema itself
    // (an ALTER TABLE ... SET DEFAULT), not anything this plugin stores -
    // storing a separate flag here could drift out of sync with reality
    // (e.g. if the default was ever changed outside this plugin).
    private function cache_images_default_enabled(): bool {
        $sth = $this->pdo->query(
            "SELECT column_default FROM information_schema.columns
             WHERE table_name = 'ttrss_feeds' AND column_name = 'cache_images'"
        );
        $row = $sth->fetch();
        return $row && trim((string)$row['column_default']) === 'true';
    }

    public function hook_prefs_tab($args) {
        if ($args != "prefFeeds") return;

        $inline_enhancement = $this->host->get($this, "inline_enhancement", true);
        $strip_tracking_pixels = $this->host->get($this, "strip_tracking_pixels", true);
        $fix_enclosure_type = $this->host->get($this, "fix_enclosure_type", true);
        $extract_og = $this->host->get($this, "extract_og", true);
        $enhance_content = $this->host->get($this, "enhance_content", false);
        $upgrade_enclosures = $this->host->get($this, "upgrade_enclosures", false);
        $kagi_source_image = $this->host->get($this, "kagi_source_image", false);
        $show_wrapper_site_link = $this->host->get($this, "show_wrapper_site_link", false);
        $cache_images_default = $this->cache_images_default_enabled();
        ?>
        <div dojoType="dijit.layout.AccordionPane"
            title="<i class='material-icons'>auto_fix_high</i> <?= __('Content Enhancement') ?>">

            <form dojoType="dijit.form.Form" style="overflow-y: auto; padding-right: 8px;">

                <?= \Controls\pluginhandler_tags($this, "save") ?>

                <script type="dojo/method" event="onSubmit" args="evt">
                    evt.preventDefault();
                    if (this.validate()) {
                        Notify.progress('Saving data...', true);
                        xhr.post("backend.php", this.getValues(), (reply) => {
                            Notify.info(reply);
                        });
                    }
                </script>

                <fieldset>
                    <legend style="font-weight: bold; font-size: 1.05em;"><?= __('Inline Image Enhancement') ?></legend>
                    <label class="checkbox">
                        <input dojoType="dijit.form.CheckBox" type="checkbox" name="inline_enhancement" value="1"
                            <?= $inline_enhancement ? 'checked' : '' ?>>
                        <?= __('Enhance inline images (srcset, lazy loading)') ?>
                    </label>
                    <p class="help-text" style="margin-left: 24px; color: #666;">
                        <?= __('Works on &lt;img&gt; tags already present in the RSS content - no article page fetch required. When an image has a srcset attribute, replaces src with the highest-resolution URL listed so the display image is not needlessly downscaled. Also converts data-src to src (some feeds ship lazy-loading markup meant for a live webpage, which never fires in an RSS reader and leaves the image blank) and removes loading="lazy" so images render immediately instead of waiting on a scroll-triggered load that TT-RSS never triggers.') ?>
                    </p>
                </fieldset>

                <fieldset>
                    <legend style="font-weight: bold; font-size: 1.05em;"><?= __('Tracking Pixel Removal') ?></legend>
                    <label class="checkbox">
                        <input dojoType="dijit.form.CheckBox" type="checkbox" name="strip_tracking_pixels" value="1"
                            <?= $strip_tracking_pixels ? 'checked' : '' ?>>
                        <?= __('Strip tracking pixels from article content') ?>
                    </label>
                    <p class="help-text" style="margin-left: 24px; color: #666;">
                        <?= __('Removes &lt;img&gt; tags declared 2px or smaller in both width and height. Publishers embed these invisible "beacon" images to detect when and how many times an article was opened; since TT-RSS caches images, loading one silently reports back to the tracker every time you view the article. Purely a content-cleanup step - runs on RSS content already in hand, no article page fetch involved.') ?>
                    </p>
                </fieldset>

                <fieldset>
                    <legend style="font-weight: bold; font-size: 1.05em;"><?= __('Enclosure Type Fixing') ?></legend>
                    <label class="checkbox">
                        <input dojoType="dijit.form.CheckBox" type="checkbox" name="fix_enclosure_type" value="1"
                            <?= $fix_enclosure_type ? 'checked' : '' ?>>
                        <?= __('Fix empty enclosure content types') ?>
                    </label>
                    <p class="help-text" style="margin-left: 24px; color: #666;">
                        <?= __('Some feeds publish image enclosures with an empty or missing MIME type. TT-RSS and API clients use the MIME type to decide whether to render an enclosure as an image thumbnail at all, so an empty type can make an otherwise-valid image silently fail to display. This infers the type (image/jpeg, image/png, etc.) from the file extension in the enclosure URL when the feed did not supply one.') ?>
                    </p>
                </fieldset>

                <fieldset>
                    <legend style="font-weight: bold; font-size: 1.05em;"><?= __('Open Graph Metadata') ?></legend>
                    <label class="checkbox">
                        <input dojoType="dijit.form.CheckBox" type="checkbox" name="extract_og" value="1"
                            <?= $extract_og ? 'checked' : '' ?>>
                        <?= __('Extract Open Graph metadata') ?>
                    </label>
                    <p class="help-text" style="margin-left: 24px; color: #666;">
                        <?= __('Open Graph (og:*) tags are metadata most web pages embed in their &lt;head&gt; for link previews (the image/title/description shown when a link is shared on social media). This fetches the article\'s own web page - not just the RSS feed entry - and uses that metadata to add a thumbnail image and fill in a missing author when the feed itself did not provide them. If a page lacks og:* tags for something, several other standard sources are tried in order: plain &lt;meta name="author"&gt;/&lt;meta name="description"&gt; tags, JSON-LD structured data (schema.org Article/BlogPosting, if the page has it), and - for author specifically, as a last resort - a &lt;a rel="author"&gt; link in the page body. If there is still no image, this falls back to the first substantial image found directly in the article\'s own HTML (skipping icons and tracking pixels), so link-aggregator feeds without any thumbnail at all (e.g. Hacker News) still get one. The fetch only happens when it is actually needed: when the RSS entry has no usable image, or its content is thin/missing. Feeds that already ship full images and content are left alone, avoiding a pointless extra request per article.') ?>
                    </p>

                    <label class="checkbox" style="margin-left: 24px;">
                        <input dojoType="dijit.form.CheckBox" type="checkbox" name="enhance_content" value="1"
                            <?= $enhance_content ? 'checked' : '' ?>>
                        <?= __('Backfill short content from og:description, falling back to extracted article text') ?>
                    </label>
                    <p class="help-text" style="margin-left: 48px; color: #666;">
                        <?= __('Only takes effect together with "Extract Open Graph metadata" above, since it reuses the same page fetch rather than fetching a second time. When an article\'s stored content is shorter than what\'s available, this prepends the page\'s og:description (its social-preview summary) to backfill it. Many sites (Wikipedia is a common example) don\'t publish an og:description at all - for those, this falls back to a simple extraction of the first substantial paragraph(s) of real article text directly from the page\'s own HTML, so link-only feed entries still get a usable summary instead of staying blank. When either the summary or the thumbnail image was assembled this way (rather than pulled from the publisher\'s own og:description/og:image), ✨ is added to the end of the article title so you can tell at a glance.') ?>
                    </p>
                </fieldset>

                <fieldset>
                    <legend style="font-weight: bold; font-size: 1.05em;"><?= __('Enclosure URL Upgrading') ?></legend>
                    <label class="checkbox">
                        <input dojoType="dijit.form.CheckBox" type="checkbox" name="upgrade_enclosures" value="1"
                            <?= $upgrade_enclosures ? 'checked' : '' ?>>
                        <?= __('Upgrade enclosure URLs from article page') ?>
                    </label>
                    <p class="help-text" style="margin-left: 24px; color: #666;">
                        <?= __('Many feeds publish a low-resolution enclosure image (a small thumbnail meant for feed-reader lists) even though a much larger version of the same photo is available on the article\'s own page via its srcset. When an article has enclosures, this fetches the article page, matches each enclosure to the corresponding image on the page by filename, and replaces the enclosure URL with the highest-resolution version found - so the image you see is not artificially capped to thumbnail size.') ?>
                    </p>
                </fieldset>

                <fieldset>
                    <legend style="font-weight: bold; font-size: 1.05em;"><?= __('Site-Specific Handling') ?></legend>
                    <label class="checkbox">
                        <input dojoType="dijit.form.CheckBox" type="checkbox" name="kagi_source_image" value="1"
                            <?= $kagi_source_image ? 'checked' : '' ?>>
                        <?= __('Kagi News: use a source article\'s image instead of the generic banner') ?>
                    </label>
                    <p class="help-text" style="margin-left: 24px; color: #666;">
                        <?= __('Only applies to kite.kagi.com articles, and only takes effect together with "Extract Open Graph metadata" above. Kagi News\'s own article page always returns the same site-wide banner image regardless of the story, so for image-less articles this parses the "Sources:" list Kagi already includes in its content and fetches one of the original source articles instead, using its real og:image. Replaces the (otherwise useless) fetch of Kagi\'s own page rather than adding an extra one.') ?>
                    </p>

                    <label class="checkbox">
                        <input dojoType="dijit.form.CheckBox" type="checkbox" name="show_wrapper_site_link" value="1"
                            <?= $show_wrapper_site_link ? 'checked' : '' ?>>
                        <?= __('Show a link to the linked site\'s other posts on wrapper/aggregator feeds') ?>
                    </label>
                    <p class="help-text" style="margin-left: 24px; color: #666;">
                        <?= __('For "wrapper" feeds where every entry just links out to another site (e.g. Hacker News), adds a small link at the top of the article to the domain it actually links to, plus a link to browse that wrapper site\'s other posts about the same domain. Recognizing that a feed is a wrapper is generic (it compares the feed\'s own site to where the article actually links, so it works for any such feed) - but the browse-page link is only added for wrapper sites this plugin has specifically been taught the URL pattern for. That list is currently just one entry:') ?>
                    </p>
                    <p class="help-text" style="margin-left: 24px; color: #666;">
                        &bull; <?= __('Hacker News (news.ycombinator.com) - links to') ?> <em>https://news.ycombinator.com/from?site=&lt;domain&gt;</em>
                    </p>
                    <p class="help-text" style="margin-left: 24px; color: #666;">
                        <?= __('Adding another wrapper site requires a plugin code change, not just a setting. No extra page fetch is involved either way.') ?>
                    </p>
                </fieldset>

                <fieldset>
                    <legend style="font-weight: bold; font-size: 1.05em;"><?= __('Image Caching Default') ?></legend>
                    <label class="checkbox">
                        <input dojoType="dijit.form.CheckBox" type="checkbox" name="cache_images_default" value="1"
                            <?= $cache_images_default ? 'checked' : '' ?>>
                        <?= __('Cache images by default for new feeds') ?>
                    </label>
                    <p class="help-text" style="margin-left: 24px; color: #666;">
                        <?= __('Unlike the other settings on this page, this is not a per-user preference - it changes the ttrss_feeds database column default, which applies instance-wide to every feed anyone subscribes to from now on (TT-RSS has no per-user override for this). Without it, cache_images defaults to off for new feeds: their images load directly from the original site every time instead of a local cached copy, which can break if the site blocks hotlinking or later removes the image.') ?>
                    </p>
                    <p class="help-text" style="margin-left: 24px;">
                        <button dojoType="dijit.form.Button" type="button"
                            onclick="return Plugins.Af_Enhance_Content.applyCacheImagesNow()">
                            <?= __('Enable caching on all my existing feeds now') ?>
                        </button>
                    </p>
                </fieldset>

                <?= \Controls\submit_tag(__("Save")) ?>
            </form>
        </div>
        <?php
    }

    public function get_prefs_js() {
        return "if (!Plugins.Af_Enhance_Content) Plugins.Af_Enhance_Content = {};

        Plugins.Af_Enhance_Content.applyCacheImagesNow = function() {
            Notify.progress('Enabling image caching...', true);
            xhr.json('backend.php', {op: 'PluginHandler', plugin: 'af_enhance_content', method: 'applyCacheImagesNow'})
                .then(function(r) { Notify.info('Enabled image caching on ' + r.updated + ' feed(s).'); });
            return false;
        };";
    }

    public function csrf_ignore($method) {
        return $method === 'save';
    }

    public function save() {
        $inline_enhancement = ($_POST['inline_enhancement'] ?? '') === '1';
        $this->host->set($this, "inline_enhancement", $inline_enhancement);

        $strip_tracking_pixels = ($_POST['strip_tracking_pixels'] ?? '') === '1';
        $this->host->set($this, "strip_tracking_pixels", $strip_tracking_pixels);

        $fix_enclosure_type = ($_POST['fix_enclosure_type'] ?? '') === '1';
        $this->host->set($this, "fix_enclosure_type", $fix_enclosure_type);

        $extract_og = ($_POST['extract_og'] ?? '') === '1';
        $this->host->set($this, "extract_og", $extract_og);

        $enhance_content = ($_POST['enhance_content'] ?? '') === '1';
        $this->host->set($this, "enhance_content", $enhance_content);

        $upgrade_enclosures = ($_POST['upgrade_enclosures'] ?? '') === '1';
        $this->host->set($this, "upgrade_enclosures", $upgrade_enclosures);

        $kagi_source_image = ($_POST['kagi_source_image'] ?? '') === '1';
        $this->host->set($this, "kagi_source_image", $kagi_source_image);

        $show_wrapper_site_link = ($_POST['show_wrapper_site_link'] ?? '') === '1';
        $this->host->set($this, "show_wrapper_site_link", $show_wrapper_site_link);

        $cache_images_default = ($_POST['cache_images_default'] ?? '') === '1';
        if ($cache_images_default !== $this->cache_images_default_enabled()) {
            $value = $cache_images_default ? 'true' : 'false';
            $this->pdo->exec("ALTER TABLE ttrss_feeds ALTER COLUMN cache_images SET DEFAULT $value");
        }

        echo __('Settings saved.');
    }

    // Bulk-applies cache_images = true to the current user's existing feeds
    // that don't already have it set - the one-time backfill half of what
    // enable-global-cache-settings.sql used to do outside of any plugin.
    // Scoped to the calling user's own feeds (unlike that script, which
    // updated every feed for every user unconditionally) to match how every
    // other per-user action in this codebase is scoped, since this button
    // lives on a per-user preferences page.
    public function applyCacheImagesNow() {
        $uid = $_SESSION['uid'] ?? null;
        header("Content-Type: application/json");
        if ($uid === null) {
            echo json_encode(["error" => "NOT_LOGGED_IN"]);
            return;
        }
        $sth = $this->pdo->prepare(
            "UPDATE ttrss_feeds SET cache_images = true WHERE owner_uid = ? AND cache_images = false"
        );
        $sth->execute([$uid]);
        echo json_encode(["updated" => $sth->rowCount()]);
    }

    // =====================================================================
    // MAIN ARTICLE FILTER HOOK
    // =====================================================================

    public function hook_article_filter($article) {
        // Diagnostic: Confirm hook is being called
        Debug::log("AF_ENHANCE_CONTENT: hook_article_filter() called for: " .
            ($article['title'] ?? 'unknown'), Debug::LOG_VERBOSE);

        // Captured before Feature 1 runs: enhance_inline_images() unconditionally
        // strips width/height attributes from every surviving <img> ("allows
        // natural high-res display"), which would erase the size evidence
        // article_has_images() needs to distinguish icon-sized share buttons
        // from real content images. The has-images decision below is made
        // against this pristine copy instead of the post-enhancement content.
        $original_content = $article['content'] ?? '';

        // Feature 1: Enhance inline images
        if ($this->host->get($this, "inline_enhancement", true)) {
            $article = $this->enhance_inline_images($article);
        }

        // Feature 2: Fix enclosure MIME types
        if ($this->host->get($this, "fix_enclosure_type", true)) {
            $article = $this->fix_enclosure_types($article);
        }

        // Feature 3 & 4 & 5: Article page fetching for OG and enclosure upgrading
        $extract_og = $this->host->get($this, "extract_og", true);
        $upgrade_enclosures = $this->host->get($this, "upgrade_enclosures", false);

        // Determine if we need to fetch the article page
        $should_fetch = false;

        // Fetch if OG extraction enabled and the article lacks images OR lacks
        // real content. These are independent conditions, checked with OR, not
        // AND: article_has_images() alone used to gate this (so feeds with
        // full inline content, e.g. NPR, The Verge, don't get a duplicate
        // og:thumbnail) but that meant an article with an image and no body
        // text (common on link-aggregator feeds like Hacker News) never got
        // fetched for og:description/content backfill either, since the image
        // check alone blocked it. Content-length is what actually determines
        // whether backfill is needed, independent of images.
        $article_for_fetch_check = ['content' => $original_content, 'enclosures' => $article['enclosures'] ?? []];
        if ($extract_og && (!$this->article_has_images($article_for_fetch_check)
                             || !$this->article_has_meaningful_content($article_for_fetch_check))) {
            $should_fetch = true;
        }

        // Fetch if enclosure upgrading enabled and article has enclosures
        if ($upgrade_enclosures && !empty($article['enclosures'])) {
            $should_fetch = true;
            // Diagnostic: Confirm enclosure upgrading decision (non-verbose for visibility)
            Debug::log("AF_ENHANCE_CONTENT: Will attempt to upgrade " .
                count($article['enclosures']) . " enclosure(s)");
        }

        if ($should_fetch) {
            $url = $article['link'] ?? '';
            if (!empty($url)) {
                $html = null;
                $kagi_source_image = $this->host->get($this, "kagi_source_image", false);

                if ($extract_og && $kagi_source_image && $this->is_kagi_news_url($url)) {
                    $html = $this->fetch_kagi_source_page($original_content);
                }

                if (empty($html)) {
                    Debug::log("af_enhance_content: Fetching article page: $url", Debug::LOG_VERBOSE);
                    $html = $this->fetch_article_page($url);
                }

                if ($html) {
                    // Extract OG metadata (needed for both og extraction and enclosure upgrading fallback)
                    $og_data = null;
                    if ($extract_og || $upgrade_enclosures) {
                        $og_data = $this->extract_og_metadata($html);
                    }

                    // Apply OG metadata if extraction is enabled. Called even
                    // when $og_data is null (page has no OG tags at all,
                    // e.g. Wikipedia) so the body-text extraction fallback
                    // inside apply_og_metadata() still gets a chance to run.
                    if ($extract_og) {
                        $article = $this->apply_og_metadata($article, $og_data ?? [], $html);
                    }

                    // Upgrade enclosure URLs if enabled
                    if ($upgrade_enclosures && !empty($article['enclosures'])) {
                        $article = $this->upgrade_enclosure_urls($article, $html, $og_data);
                    }
                }
            }
        }

        // Wrapper-feed site link (Hacker News, etc.) - no fetch needed,
        // uses only data already present in $article.
        if ($this->host->get($this, "show_wrapper_site_link", false)) {
            $article = $this->add_wrapper_site_link($article);
        }

        return $article;
    }

    /**
     * Hook: display-time cleanup applied to every API response (all clients,
     * including FreshAPI/Capy Reader).
     *
     * 1. Ensures content is never null (prevents TypeError in
     *    DiskCache::rewrite_urls(), which core calls right after this hook).
     * 2. Strips tracking pixels again at display time. hook_article_filter()
     *    only fixes articles at import, so articles stored before that feature
     *    existed keep their pixels forever; re-stripping here cleans the whole
     *    backlog without touching the database. This hook runs BEFORE
     *    DiskCache::rewrite_urls(), so original URLs (with their /tracking/ or
     *    -pixel. giveaways) and any stored width/height are still visible.
     * 3. Clears flavor_image when it points at a tracking-pixel URL. Core
     *    computes flavor_image from the raw stored content before this hook
     *    runs, so on old articles it can be the pixel itself - which loads
     *    "successfully" as a 1x1 and defeats client-side onerror fallbacks.
     */
    public function hook_render_article_api($row) {
        // Extract article from wrapper (may be 'headline', 'article', or unwrapped)
        $article = $row['headline'] ?? $row['article'] ?? $row;

        // Ensure content is never null (prevents TypeError)
        if (!isset($article['content']) || $article['content'] === null) {
            $article['content'] = '';

            Debug::log("af_enhance_content: Fixed null/missing content for article: " .
                ($article['title'] ?? 'unknown'), Debug::LOG_VERBOSE);
        }

        if ($this->host->get($this, "strip_tracking_pixels", true)) {
            if (stripos($article['content'], '<img') !== false) {
                $stripped = preg_replace_callback(
                    '/<img\s+([^>]*?)>/is',
                    function($matches) {
                        if ($this->is_tracking_pixel($matches[0]) ||
                            $this->has_tracking_pixel_url($matches[0])) {
                            return '';
                        }
                        return $matches[0];
                    },
                    $article['content']
                );
                // preg_replace_callback returns null on regex failure; never
                // replace real content with null
                if ($stripped !== null) {
                    $article['content'] = $stripped;
                }
            }

            if (!empty($article['flavor_image']) &&
                preg_match('#/tracking[/.]|[-_]pixel\.#i', $article['flavor_image'])) {
                $article['flavor_image'] = '';
            }
        }

        $article['content'] = $this->fix_escaped_anchor_tags($article['content']);

        // Always return unwrapped article (callback will handle it)
        return $article;
    }

    // Some publishers (e.g. KPBS, on an Arc Publishing-style CMS) serialize
    // a structured "credit link" component to their RSS feed as a broken,
    // HTML-entity-escaped <a> tag instead of real markup - the CMS's own
    // website renders the same underlying data correctly via its own
    // component/JS layer, but the feed itself, as delivered to every
    // subscriber, contains literal text like:
    //   &lt;a href="https://example.com/staff/x" data-cms-id="..." link-data="{...}"&gt;Jane Doe&lt;/a&gt;
    // Browsers render that as visible text rather than a link, since the
    // angle brackets are entities, not real markup. This decodes it back
    // into a clean, real anchor tag, dropping the CMS-internal attributes.
    // Runs at display time (every API response) rather than only at import,
    // so it also fixes articles already stored before this existed.
    private function fix_escaped_anchor_tags($content) {
        if ($content === null || $content === '' || stripos($content, '&lt;a ') === false) {
            return $content;
        }

        $fixed = preg_replace(
            '/&lt;a\s+href="([^"]+)"[^&]*?&gt;(.*?)&lt;\/a&gt;/is',
            '<a href="$1">$2</a>',
            $content
        );

        // preg_replace returns null on regex failure; never replace real
        // content with null
        return $fixed !== null ? $fixed : $content;
    }

    // Headline list responses (getHeadlines with show_excerpt) compute their
    // "excerpt" field from a content_preview that core builds by truncating
    // the RAW stored content BEFORE HOOK_RENDER_ARTICLE_API ever runs - so
    // the fix above never reaches it, and the truncation itself often cuts
    // the escaped anchor tag off mid-attribute (no closing &lt;/a&gt;), which
    // would make fix_escaped_anchor_tags() a no-op if applied afterward.
    // Instead, this recomputes content_preview from the full fixed content,
    // the same way core does (strip_tags + truncate_string), so the excerpt
    // shown in the article list matches what the reader view already shows.
    public function hook_query_headlines($line, $excerpt_length) {
        if (isset($line["content"]) && stripos($line["content"], '&lt;a ') !== false) {
            $fixed_content = $this->fix_escaped_anchor_tags($line["content"]);
            $line["content_preview"] = truncate_string(strip_tags($fixed_content), $excerpt_length);
        }

        return $line;
    }

    // =====================================================================
    // FEATURE 1: INLINE IMAGE ENHANCEMENT
    // =====================================================================

    private function enhance_inline_images($article) {
        if (!isset($article['content']) || empty($article['content'])) {
            return $article;
        }

        $content = $article['content'];
        $modifications = [];
        $strip_tracking_pixels = $this->host->get($this, "strip_tracking_pixels", true);

        // Process all img tags
        $content = preg_replace_callback(
            '/<img\s+([^>]*?)>/is',
            function($matches) use (&$modifications, $strip_tracking_pixels) {
                return $this->enhance_img_tag($matches[0], $modifications, $strip_tracking_pixels);
            },
            $content
        );

        if (!empty($modifications)) {
            $article['content'] = $content;
            $mod_summary = implode(', ', array_unique($modifications));
            Debug::log("af_enhance_content: Enhanced article: " .
                ($article['title'] ?? 'unknown') . " - Modifications: $mod_summary",
                Debug::LOG_VERBOSE);
        }

        return $article;
    }

    /**
     * Extract declared width/height attribute values from an <img ...> tag string.
     * Either entry is null if the attribute is absent or non-numeric.
     */
    private function get_declared_dimensions($img_tag) {
        $width = null;
        $height = null;

        if (preg_match('/\swidth\s*=\s*["\']?\s*(\d+)(?:px)?\s*["\']?/i', $img_tag, $m)) {
            $width = (int)$m[1];
        }
        if (preg_match('/\sheight\s*=\s*["\']?\s*(\d+)(?:px)?\s*["\']?/i', $img_tag, $m)) {
            $height = (int)$m[1];
        }

        return [$width, $height];
    }

    /**
     * Detect tracking/beacon pixels by declared size alone: <img> tags with both
     * width and height present and <= 2px. Deliberately not URL/domain-based —
     * no hardcoded tracker list to invent or keep up to date. Known limitation:
     * misses beacons that omit width/height attributes entirely (see
     * has_tracking_pixel_url() for that case).
     */
    private function is_tracking_pixel($img_tag) {
        [$width, $height] = $this->get_declared_dimensions($img_tag);
        return $width !== null && $height !== null && $width <= 2 && $height <= 2;
    }

    /**
     * Detect tracking/beacon pixels whose URL path/filename gives them away
     * (e.g. NPR's own RSS beacon: media.npr.org/include/images/tracking/npr-rss-pixel.png),
     * for pixels with no declared width/height for is_tracking_pixel() to catch.
     * Matches the same heuristic already used client-side in the Rhesus web reader
     * (ArticleReader.vue's parseHero/processContent). This must run here, at
     * import time, before TT-RSS's own image-caching layer rewrites <img src> to
     * a local cache path — once rewritten, the "tracking"/"pixel" URL segment is
     * gone and neither this check nor the client-side one can see it anymore.
     */
    private function has_tracking_pixel_url($img_tag) {
        if (!preg_match('/\s(?:data-)?src\s*=\s*["\']([^"\']+)["\']/i', $img_tag, $m)) {
            return false;
        }
        return preg_match('#/tracking[/.]|[-_]pixel\.#i', $m[1]) === 1;
    }

    /**
     * Detect small icon/share-button images by declared size: <img> tags with both
     * width and height present and <= 50px. Many themes/plugins (e.g. "Social Media
     * Feather") inject share-button icons directly into post content, which
     * shouldn't count as "the article already has a real image" when deciding
     * whether to fall back to og:image. Images with no declared dimensions are
     * treated as real content, since they can't be judged by this heuristic.
     */
    private function is_icon_sized_image($img_tag) {
        [$width, $height] = $this->get_declared_dimensions($img_tag);
        return $width !== null && $height !== null && $width <= 50 && $height <= 50;
    }

    /**
     * Re-quote single-quoted HTML attributes whose value contains an internal
     * apostrophe (e.g. alt='The nation's capital...') as double-quoted. PHP's
     * strip_tags() - used by TT-RSS core to compute the article-list excerpt -
     * has no real quote-tracking and gets confused by this, losing track of
     * where the tag ends and swallowing the following real text. Real HTML
     * parsers (browsers, DOMParser) handle the original single-quoting fine via
     * proper tokenization, which is why this only breaks the excerpt, not the
     * full-content reader view.
     *
     * Finds each single-quoted value's TRUE closing quote - the ' immediately
     * followed by the next attribute or the tag's end - via a lazy capture with
     * a lookahead, mirroring the tokenization strip_tags() gets wrong. Only
     * re-quotes when the value actually contains an apostrophe (the ambiguous
     * case); leaves ordinary single-quoted attributes untouched. Skips
     * re-quoting if the value contains a literal double-quote (rare; would need
     * its own escaping strategy, not worth solving here).
     */
    private function fix_ambiguous_single_quotes($img_tag) {
        return preg_replace_callback(
            '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*\'(.*?)\'(?=\s+[a-zA-Z_:][-a-zA-Z0-9_:.]*\s*=|\s*\/?>)/is',
            function($m) {
                [$whole, $attr, $value] = $m;
                if (strpos($value, '"') !== false) return $whole;   // leave ambiguous-but-unsafe case alone
                if (strpos($value, "'") === false) return $whole;   // no apostrophe, nothing ambiguous
                return $attr . '="' . $value . '"';
            },
            $img_tag
        );
    }

    private function enhance_img_tag($img_tag, &$modifications, $strip_tracking_pixels = true) {
        $requoted = $this->fix_ambiguous_single_quotes($img_tag);
        if ($requoted !== $img_tag) {
            $modifications[] = 'requoted ambiguous attribute';
            $img_tag = $requoted;
        }

        if ($strip_tracking_pixels &&
            ($this->is_tracking_pixel($img_tag) || $this->has_tracking_pixel_url($img_tag))) {
            $modifications[] = 'removed tracking pixel';
            return '';
        }

        $original = $img_tag;

        // Step 1: Handle lazy loading - convert data-src to src.
        // Treat a data: URI as "no real src" since it's a placeholder (e.g. 1px transparent GIF).
        if (preg_match('/data-src\s*=\s*["\']([^"\']+)["\']/i', $img_tag, $data_src_match)) {
            $has_real_src = preg_match('/\ssrc\s*=\s*["\'][^"\']+["\']/i', $img_tag) &&
                            !preg_match('/\ssrc\s*=\s*["\']data:[^"\']*["\']/i', $img_tag);
            if (!$has_real_src) {
                $img_tag = preg_replace('/\s+src\s*=\s*["\'][^"\']*["\']/i', '', $img_tag);
                $img_tag = preg_replace(
                    '/data-src\s*=/i',
                    'src=',
                    $img_tag,
                    1
                );
                $modifications[] = 'data-src->src';
            }
        }

        // Normalize data-srcset to srcset so Step 2 can extract the highest resolution.
        if (preg_match('/data-srcset\s*=\s*["\']([^"\']+)["\']/i', $img_tag)) {
            $img_tag = preg_replace('/data-srcset\s*=/i', 'srcset=', $img_tag, 1);
            $modifications[] = 'data-srcset->srcset';
        }

        // Step 2: Rewrite src to use highest resolution from srcset
        if (preg_match('/srcset\s*=\s*["\']([^"\']+)["\']/i', $img_tag, $srcset_match)) {
            $srcset = $srcset_match[1];
            $highest_res_url = $this->extract_highest_res_from_srcset($srcset);

            if ($highest_res_url) {
                // Check if src attribute exists
                if (preg_match('/\ssrc\s*=/i', $img_tag)) {
                    // Rewrite existing src (URL already HTML-encoded, don't double-encode)
                    $img_tag = preg_replace(
                        '/(\s)src\s*=\s*["\'][^"\']*["\']/i',
                        '$1src="' . $highest_res_url . '"',
                        $img_tag
                    );
                    $modifications[] = 'srcset->src';
                } else {
                    // Add src attribute from srcset (improves browser compatibility)
                    $img_tag = str_replace('<img', '<img src="' . $highest_res_url . '"', $img_tag);
                    $modifications[] = 'added src from srcset';
                }
            }
        }

        // Step 3: Remove loading="lazy" attribute
        if (preg_match('/\s+loading\s*=\s*["\']?lazy["\']?/i', $img_tag)) {
            $img_tag = preg_replace('/\s+loading\s*=\s*["\']?lazy["\']?/i', '', $img_tag);
            $modifications[] = 'removed loading=lazy';
        }

        // Step 4: Remove conflicting size attributes after extracting best resolution
        // These attributes can cause RSS readers to use lower resolution images

        // Remove srcset (already extracted best URL to src)
        if (preg_match('/\s+srcset\s*=\s*["\'][^"\']*["\']/i', $img_tag)) {
            $img_tag = preg_replace('/\s+srcset\s*=\s*["\'][^"\']*["\']/i', '', $img_tag);
            $modifications[] = 'removed srcset';
        }

        // Remove sizes (no longer needed without srcset)
        if (preg_match('/\s+sizes\s*=\s*["\'][^"\']*["\']/i', $img_tag)) {
            $img_tag = preg_replace('/\s+sizes\s*=\s*["\'][^"\']*["\']/i', '', $img_tag);
            $modifications[] = 'removed sizes';
        }

        // Remove width attribute (allows natural high-res display)
        if (preg_match('/\s+width\s*=\s*["\'][^"\']*["\']/i', $img_tag)) {
            $img_tag = preg_replace('/\s+width\s*=\s*["\'][^"\']*["\']/i', '', $img_tag);
            $modifications[] = 'removed width';
        }

        // Remove height attribute (allows natural high-res display)
        if (preg_match('/\s+height\s*=\s*["\'][^"\']*["\']/i', $img_tag)) {
            $img_tag = preg_replace('/\s+height\s*=\s*["\'][^"\']*["\']/i', '', $img_tag);
            $modifications[] = 'removed height';
        }

        return $img_tag;
    }

    /**
     * Target display width for srcset selection. Candidates at least this wide
     * are "big enough"; among those the SMALLEST is chosen, so multi-thousand-
     * pixel originals (e.g. a 4764w camera original) don't get cached and
     * proxied to every client when a ~1600w variant renders identically.
     * Candidates below the target fall back to largest-available. Density
     * descriptors (2x) are compared as roughly (density * 1000)px wide.
     */
    private const SRCSET_TARGET_WIDTH = 1600;

    /**
     * Tokenizes a srcset attribute value into url/descriptor pairs.
     *
     * A plain explode(',') is unsafe here: some CDNs (Substack's
     * substackcdn.com/image/fetch/... transform URLs, for one) embed
     * literal, un-encoded commas *within* a single srcset entry's own URL,
     * as part of the URL's own transform-parameter path segment (e.g.
     * ".../fetch/$s_!hash!,w_1456,c_limit,f_auto,q_auto:good,fl_progressive:
     * steep/<url-encoded original>"). explode(',') shreds that one entry
     * into several garbage fragments - the last fragment still ends in a
     * valid-looking width descriptor ("...jpeg 1456w"), so it was getting
     * accepted here as a legitimate candidate even though it has no
     * scheme/host of its own. That fragment then got written into
     * <img src>, and TT-RSS's own image cache failed to fetch it entirely
     * once it resolved the now-relative-looking URL against the feed's
     * site instead of the actual CDN host (confirmed directly against this
     * plugin's own live docker.log output).
     *
     * Tokenizes the same way TT-RSS's own RSSUtils::decode_srcset() does
     * instead: an entry only ends at a comma immediately followed by
     * another whitespace-bounded entry, so commas embedded inside a single
     * URL survive intact.
     *
     * @return array<int, array{url: string, size: string}>
     */
    private function decode_srcset_entries($srcset) {
        preg_match_all(
            '/(?:\A|,)\s*(?P<url>(?!,)\S+(?<!,))\s*(?P<size>\s\d+(?:\.\d+)?[wx]|)\s*(?=,|\Z)/i',
            $srcset, $matches, PREG_SET_ORDER
        );
        return array_map(fn(array $m) => ['url' => trim($m['url']), 'size' => trim($m['size'])], $matches);
    }

    private function extract_highest_res_from_srcset($srcset) {
        $sources = $this->decode_srcset_entries($srcset);

        $best_over_width = PHP_FLOAT_MAX;  // smallest candidate >= target
        $best_over_url = null;
        $best_under_width = 0;             // largest candidate < target (fallback)
        $best_under_url = null;
        $first_bare_url = null;            // descriptor-less entry (last resort)

        foreach ($sources as $source) {
            $url = $source['url'];
            $size = $source['size'];

            if ($size !== '' && preg_match('/^(\d+(?:\.\d+)?)(w|x)$/i', $size, $match)) {
                $value = floatval($match[1]);
                $descriptor = strtolower($match[2]);

                $comparable_width = ($descriptor === 'w') ? $value : $value * 1000;

                if ($comparable_width >= self::SRCSET_TARGET_WIDTH) {
                    if ($comparable_width < $best_over_width) {
                        $best_over_width = $comparable_width;
                        $best_over_url = $url;
                    }
                } elseif ($comparable_width > $best_under_width) {
                    $best_under_width = $comparable_width;
                    $best_under_url = $url;
                }
            } elseif ($url !== '') {
                if ($first_bare_url === null) {
                    $first_bare_url = $url;
                }
            }
        }

        return $best_over_url ?? $best_under_url ?? $first_bare_url;
    }

    // =====================================================================
    // FEATURE 2: ENCLOSURE MIME TYPE FIXING
    // =====================================================================

    private function fix_enclosure_types($article) {
        if (!isset($article['enclosures']) || !is_array($article['enclosures'])) {
            return $article;
        }

        $modified = false;

        foreach ($article['enclosures'] as &$enclosure) {
            $type = $enclosure->type ?? '';

            if (empty($type) || $type === 'image/generic') {
                $url = $enclosure->link ?? '';

                if (!empty($url)) {
                    $inferred_type = $this->infer_mime_type($url);

                    if ($inferred_type) {
                        $enclosure->type = $inferred_type;
                        $modified = true;

                        Debug::log("af_enhance_content: Set type to '$inferred_type' for: $url",
                            Debug::LOG_VERBOSE);
                    }
                }
            }
        }

        if ($modified) {
            Debug::log("af_enhance_content: Fixed enclosure types for article: " .
                ($article['title'] ?? 'unknown'), Debug::LOG_VERBOSE);
        }

        return $article;
    }

    // =====================================================================
    // FEATURE 3: ARTICLE PAGE FETCHING
    // =====================================================================

    private function fetch_article_page($url) {
        $options = [
            'url' => $url,
            'timeout' => 10,
            'followlocation' => true,
            'useragent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ];

        $response = UrlHelper::fetch($options);

        if (!$response) {
            Debug::log("af_enhance_content: Failed to fetch: $url (may be blocked by site)", Debug::LOG_VERBOSE);
            return null;
        }

        $content_length = strlen($response);
        Debug::log("af_enhance_content: Successfully fetched $content_length bytes from: $url", Debug::LOG_VERBOSE);

        return $response;
    }

    // =====================================================================
    // FEATURE 6: SITE-SPECIFIC HANDLING (Kagi News, wrapper feed links)
    // =====================================================================
    //
    // Wrapper feed detection (below) is fully generic: it compares the
    // feed's own site_url against the article's link, so it fires for any
    // link-aggregator/wrapper feed (Hacker News, Lobsters, link-only Reddit
    // feeds, etc.), not just one named site. Only the URL-building step for
    // a *specific* wrapper (currently just Hacker News's /from?site= page)
    // is genuinely site-specific - add more cases there, not in detection.
    //
    // Kagi News (kite.kagi.com) articles are AI-generated roundups: the RSS
    // content already ends with a "Sources:" section (a uniform list of
    // <a href> links to every original article the story was aggregated
    // from), but kite.kagi.com's own page always returns the SAME generic
    // og:image (a site-wide banner, not per-article) regardless of the
    // story. Rather than fetch kite.kagi.com's page and accept that banner,
    // this parses the already-present Sources list and fetches one of the
    // original articles instead, using its real og:image. No extra fetch
    // vs. the normal path - it replaces the (useless) kite.kagi.com fetch,
    // it doesn't add to it.

    private function is_kagi_news_url($url) {
        return parse_url($url, PHP_URL_HOST) === 'kite.kagi.com';
    }

    private function extract_kagi_source_urls($content) {
        if (empty($content) || !preg_match('#<h3>\s*Sources:?\s*</h3>\s*<ul>(.*?)</ul>#is', $content, $m)) {
            return [];
        }

        preg_match_all('/<a\s+href=[\'"]([^\'"]+)[\'"]/i', $m[1], $links);
        return $links[1] ?? [];
    }

    // Tries each Sources link in turn (capped at KAGI_SOURCE_FETCH_LIMIT)
    // until one both fetches successfully and has a usable og:image.
    // Returns that page's HTML (so the caller's normal OG-extraction path
    // runs unchanged against it), or null if none worked.
    private function fetch_kagi_source_page($content) {
        $source_urls = $this->extract_kagi_source_urls($content);

        foreach (array_slice($source_urls, 0, self::KAGI_SOURCE_FETCH_LIMIT) as $source_url) {
            $html = $this->fetch_article_page($source_url);
            if (empty($html)) {
                continue;
            }

            $og = $this->extract_og_metadata($html);
            if (!empty($og['image'])) {
                Debug::log("af_enhance_content: Using Kagi source page for image: $source_url", Debug::LOG_VERBOSE);
                return $html;
            }
        }

        return null;
    }

    // Prepends a small "browse more from this domain" link to content when
    // the article's feed is a wrapper/aggregator (its own site_url domain
    // differs from where the article actually links) and we know how to
    // build a browse-URL for that specific wrapper. No fetch required -
    // uses only data TT-RSS already passes into HOOK_ARTICLE_FILTER.
    private function add_wrapper_site_link($article) {
        $feed_host = $this->normalize_host($article['feed']['site_url'] ?? '');
        $article_host = $this->normalize_host($article['link'] ?? '');

        if (empty($feed_host) || empty($article_host) || $feed_host === $article_host) {
            return $article;
        }

        $browse_url = $this->wrapper_site_browse_url($feed_host, $article_host);
        if (empty($browse_url)) {
            return $article;
        }

        $article['content'] = '<p><small><a href="' . htmlspecialchars($browse_url) . '">' .
            htmlspecialchars($article_host) . '</a></small></p>' . ($article['content'] ?? '');

        Debug::log("af_enhance_content: Added wrapper site link for $article_host", Debug::LOG_VERBOSE);

        return $article;
    }

    private function normalize_host($url) {
        $host = parse_url($url, PHP_URL_HOST);
        return $host ? preg_replace('/^www\./i', '', strtolower($host)) : null;
    }

    // Site-specific: builds a "browse more from this domain" URL for
    // wrapper feeds whose own site offers that kind of per-domain listing.
    // Add more cases here as they come up - this is the ONLY part of
    // wrapper-link handling that's specific to a particular site.
    private function wrapper_site_browse_url($feed_host, $article_host) {
        if ($feed_host === 'news.ycombinator.com') {
            return 'https://news.ycombinator.com/from?site=' . rawurlencode($article_host);
        }

        return null;
    }

    // =====================================================================
    // FEATURE 4: OPEN GRAPH METADATA EXTRACTION
    // =====================================================================

    private function extract_og_metadata($html) {
        $original_html = $html;

        // Only parse the head section for efficiency
        $head_end = stripos($html, '</head>');
        if ($head_end !== false) {
            $html = substr($html, 0, $head_end + 7);
        }

        $og_data = [
            'image' => null,
            'image_width' => null,
            'image_height' => null,
            'image_alt' => null,
            'description' => null,
            'author' => null,
            'author_is_twitter' => false,
            'tags' => [],
            'title' => null,
            'site_name' => null,
            'type' => null,
            'published_time' => null,
        ];

        // Candidates from each source, collected during the single pass
        // below and reconciled by priority afterward (rather than letting
        // whichever tag happens to appear first/last in the document win) -
        // og:article:author is the most specific/authoritative source, then
        // the plain <meta name="author/description"> tags (the oldest,
        // most universal HTML convention, predating Open Graph), then
        // Twitter's fields (often just the site's own handle, not a real
        // byline - see the RIPE Labs case this was built for).
        $og_article_author = null;
        $meta_author = null;
        $twitter_author = null;
        $og_description = null;
        $meta_description = null;
        $twitter_description = null;

        preg_match_all('/<meta\s+[^>]*>/i', $html, $meta_matches);

        foreach ($meta_matches[0] as $meta_tag) {
            $property = null;
            $name = null;
            $content = null;

            if (preg_match('/property\s*=\s*["\']([^"\']+)["\']/i', $meta_tag, $m)) {
                $property = $m[1];
            }
            if (preg_match('/name\s*=\s*["\']([^"\']+)["\']/i', $meta_tag, $m)) {
                $name = $m[1];
            }
            if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $meta_tag, $m)) {
                $content = $m[1];
            }

            if (empty($content)) continue;

            $key = $property ?: $name;
            if (empty($key)) continue;

            switch (strtolower($key)) {
                case 'og:image':
                    if (empty($og_data['image'])) {
                        $og_data['image'] = html_entity_decode($content);
                    }
                    break;
                case 'og:image:width':
                    $og_data['image_width'] = (int)$content;
                    break;
                case 'og:image:height':
                    $og_data['image_height'] = (int)$content;
                    break;
                case 'og:image:alt':
                    $og_data['image_alt'] = html_entity_decode($content);
                    break;
                case 'og:description':
                    $og_description = html_entity_decode($content);
                    break;
                case 'description':
                    if (empty($meta_description)) {
                        $meta_description = html_entity_decode($content);
                    }
                    break;
                case 'og:title':
                    $og_data['title'] = html_entity_decode($content);
                    break;
                case 'og:site_name':
                    $og_data['site_name'] = html_entity_decode($content);
                    break;
                case 'og:type':
                    $og_data['type'] = $content;
                    break;
                case 'og:article:author':
                case 'article:author':
                    $og_article_author = html_entity_decode($content);
                    break;
                case 'author':
                    if (empty($meta_author)) {
                        $meta_author = html_entity_decode($content);
                    }
                    break;
                case 'og:article:tag':
                case 'article:tag':
                    $og_data['tags'][] = html_entity_decode($content);
                    break;
                case 'og:article:published_time':
                case 'article:published_time':
                    $og_data['published_time'] = $content;
                    break;
                case 'twitter:image':
                    if (empty($og_data['image'])) {
                        $og_data['image'] = html_entity_decode($content);
                    }
                    break;
                case 'twitter:description':
                    if (empty($twitter_description)) {
                        $twitter_description = html_entity_decode($content);
                    }
                    break;
                case 'twitter:creator':
                case 'twitter:site':
                    if (empty($twitter_author)) {
                        $twitter_author = html_entity_decode($content);
                    }
                    break;
            }
        }

        // <link rel="image_src"> is an older, pre-Open-Graph convention for
        // a representative image - only used if og:image/twitter:image
        // didn't already supply one.
        if (empty($og_data['image'])
                && preg_match('/<link\s+[^>]*rel\s*=\s*["\']image_src["\'][^>]*>/i', $html, $link_tag)
                && preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $link_tag[0], $href)) {
            $og_data['image'] = html_entity_decode($href[1]);
        }

        // Reconcile author: og:article:author > meta author > twitter
        // handle. Order-independent - whichever source exists wins by
        // priority, regardless of which tag appeared first in the HTML.
        if (!empty($og_article_author)) {
            $og_data['author'] = $og_article_author;
            $og_data['author_is_twitter'] = false;
        } elseif (!empty($meta_author)) {
            $og_data['author'] = $meta_author;
            $og_data['author_is_twitter'] = false;
        } elseif (!empty($twitter_author)) {
            $og_data['author'] = $twitter_author;
            $og_data['author_is_twitter'] = true;
        }

        // Reconcile description with the same priority idea: og:description
        // > meta description > twitter:description.
        if (!empty($og_description)) {
            $og_data['description'] = $og_description;
        } elseif (!empty($meta_description)) {
            $og_data['description'] = $meta_description;
        } elseif (!empty($twitter_description)) {
            $og_data['description'] = $twitter_description;
        }

        // Body-level fallbacks (JSON-LD, then <a rel="author">) - only
        // consulted for whatever the head tags above didn't supply. Uses
        // the original, untruncated HTML since these aren't confined to
        // <head>.
        if (empty($og_data['author']) || empty($og_data['description']) || empty($og_data['image'])) {
            $json_ld = $this->extract_json_ld($original_html);
            if ($json_ld) {
                if (empty($og_data['author']) && !empty($json_ld['author'])) {
                    $og_data['author'] = $json_ld['author'];
                    $og_data['author_is_twitter'] = false;
                }
                if (empty($og_data['description']) && !empty($json_ld['description'])) {
                    $og_data['description'] = $json_ld['description'];
                }
                if (empty($og_data['image']) && !empty($json_ld['image'])) {
                    $og_data['image'] = $json_ld['image'];
                }
            }
        }

        if (empty($og_data['author'])) {
            $rel_author = $this->extract_rel_author($original_html);
            if (!empty($rel_author)) {
                $og_data['author'] = $rel_author;
                $og_data['author_is_twitter'] = false;
            }
        }

        // Return null only if we found absolutely nothing useful
        // Tags can be useful even without image/description/author
        if (empty($og_data['image']) && empty($og_data['description']) && empty($og_data['author']) && empty($og_data['tags'])) {
            return null;
        }

        return $og_data;
    }

    // Scans for a schema.org Article/BlogPosting/NewsArticle/TechArticle
    // JSON-LD block and pulls author name, description, and image from it.
    // Handles a single object, an array of objects, or an @graph wrapper -
    // the common shapes seen in the wild. Defensive throughout: malformed
    // or unexpected JSON just falls through to null rather than erroring,
    // since this is a best-effort fallback, not a strict parser.
    private function extract_json_ld($html) {
        if (empty($html) || !preg_match_all(
                '#<script[^>]+type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $json_text) {
            $data = json_decode(trim($json_text), true);
            if (!is_array($data)) {
                continue;
            }

            $candidates = $data['@graph'] ?? (array_is_list($data) ? $data : [$data]);

            foreach ($candidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $types = $candidate['@type'] ?? [];
                $types = is_array($types) ? $types : [$types];
                $is_article = !empty(array_intersect(
                    array_map('strtolower', $types),
                    ['article', 'newsarticle', 'blogposting', 'techarticle']
                ));

                if (!$is_article) {
                    continue;
                }

                $author = $candidate['author'] ?? null;
                $author_name = null;
                if (is_string($author)) {
                    $author_name = $author;
                } elseif (is_array($author)) {
                    $first_author = array_is_list($author) ? ($author[0] ?? null) : $author;
                    $author_name = is_array($first_author) ? ($first_author['name'] ?? null) : $first_author;
                }

                $image = $candidate['image'] ?? null;
                if (is_array($image)) {
                    $image = array_is_list($image) ? ($image[0] ?? null) : ($image['url'] ?? null);
                    if (is_array($image)) {
                        $image = $image['url'] ?? null;
                    }
                }

                return [
                    'author' => is_string($author_name) ? $author_name : null,
                    'description' => is_string($candidate['description'] ?? null) ? $candidate['description'] : null,
                    'image' => is_string($image) ? $image : null,
                ];
            }
        }

        return null;
    }

    // <a rel="author"> is a standard HTML link-type for attributing a page
    // to its author - common on Blogger/WordPress-style sites even when no
    // machine-readable author metadata exists in <head> at all. rel is a
    // space-separated token list (e.g. "author nofollow"), so this matches
    // "author" as a whole token, not a substring.
    private function extract_rel_author($html) {
        if (empty($html)) {
            return null;
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        if (!$loaded) {
            return null;
        }

        $xpath = new DOMXPath($doc);
        $nodes = $xpath->query("//a[contains(concat(' ', normalize-space(@rel), ' '), ' author ')]");

        if ($nodes->length === 0) {
            return null;
        }

        $text = trim(preg_replace('/\s+/', ' ', $nodes->item(0)->textContent));
        return $text !== '' ? $text : null;
    }

    // Fallback for pages with no og:description (or no OG tags at all).
    // Hand-rolled DOMDocument heuristic, not a full Readability port: strips
    // obvious non-article chrome, prefers an <article>/<main> container if
    // present, and takes the first one or two substantial <p> elements as
    // the summary. Reuses the already-fetched page HTML - no second fetch.
    private function extract_body_summary($html) {
        if (empty($html)) {
            return null;
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        if (!$loaded) {
            return null;
        }

        $xpath = new DOMXPath($doc);

        foreach (['script', 'style', 'nav', 'header', 'footer', 'aside', 'form', 'iframe'] as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                $node->parentNode->removeChild($node);
            }
        }

        $container = $xpath->query('//article')->item(0)
            ?? $xpath->query('//main')->item(0)
            ?? $doc;

        $paragraphs = [];
        foreach ($container->getElementsByTagName('p') as $p) {
            $text = trim(preg_replace('/\s+/', ' ', $p->textContent));
            if (strlen($text) >= 60) {
                $paragraphs[] = $text;
            }
            if (count($paragraphs) >= 2) {
                break;
            }
        }

        if (empty($paragraphs)) {
            return null;
        }

        return implode(' ', $paragraphs);
    }

    // Fallback for pages with no og:image (or no OG tags at all). Same
    // container-detection as extract_body_summary(): prefers an
    // <article>/<main> region, then takes the first <img> that isn't
    // icon-sized or a tracking pixel (reusing the same filters
    // article_has_images() uses), resolved to an absolute URL against the
    // article's own page.
    private function extract_body_image($html, $base_url) {
        if (empty($html)) {
            return null;
        }

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        if (!$loaded) {
            return null;
        }

        $xpath = new DOMXPath($doc);

        foreach (['script', 'style', 'nav', 'header', 'footer', 'aside', 'form', 'iframe'] as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                $node->parentNode->removeChild($node);
            }
        }

        $container = $xpath->query('//article')->item(0)
            ?? $xpath->query('//main')->item(0)
            ?? $doc;

        foreach ($container->getElementsByTagName('img') as $img) {
            $img_html = $doc->saveHTML($img);
            if ($this->is_icon_sized_image($img_html) || $this->has_tracking_pixel_url($img_html)) {
                continue;
            }

            $src = $img->getAttribute('src') ?: $img->getAttribute('data-src');
            if (empty($src)) {
                continue;
            }

            return $this->resolve_url($src, $base_url);
        }

        return null;
    }

    // Resolves a possibly-relative URL (as commonly found in raw page HTML,
    // unlike og:image which is required to be absolute per the OG spec)
    // against a base page URL. Handles protocol-relative (//host/path),
    // root-relative (/path), and document-relative (path) forms.
    private function resolve_url($url, $base_url) {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $base = parse_url($base_url);
        if (empty($base['scheme']) || empty($base['host'])) {
            return $url;
        }
        $origin = $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');

        if (str_starts_with($url, '//')) {
            return $base['scheme'] . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }

        $base_path = $base['path'] ?? '/';
        $dir = substr($base_path, 0, strrpos($base_path, '/') + 1);
        return $origin . $dir . $url;
    }

    private function apply_og_metadata($article, $og_data, $html = '') {
        // Tracks whether anything in this call was assembled via body-page
        // extraction rather than pulled from clean og:* metadata, so the
        // title can be marked with EXTRACTED_SUMMARY_ICON once at the end
        // regardless of whether it was the image, the summary, or both.
        $used_extraction = false;

        // Add og:image as enclosure if we don't have image enclosures,
        // falling back to extracting a real image from the article's own
        // page when there's no og:image either - mirrors the content
        // backfill below, since sites lacking og:description often lack
        // og:image too (e.g. Wikipedia has neither).
        $image_url = $og_data['image'] ?? null;
        // og:image is required to be absolute per the OG spec, but not
        // every site follows that (e.g. arxiv.org emits a root-relative
        // path) - resolve_url() is a no-op on already-absolute URLs.
        if (!empty($image_url) && !empty($article['link'])) {
            $image_url = $this->resolve_url($image_url, $article['link']);
        }
        $image_from_extraction = false;
        if (empty($image_url) && !empty($html) && !empty($article['link'])) {
            $image_url = $this->extract_body_image($html, $article['link']);
            $image_from_extraction = !empty($image_url);
        }

        if (!empty($image_url)) {
            $has_image_enclosure = false;

            if (!empty($article['enclosures'])) {
                foreach ($article['enclosures'] as $enc) {
                    $type = $enc->type ?? '';
                    if (strpos($type, 'image/') === 0) {
                        $has_image_enclosure = true;
                        break;
                    }
                }
            }

            if (!$has_image_enclosure) {
                $enclosure = new stdClass();
                $enclosure->link = $image_url;
                $enclosure->type = $this->infer_mime_type($image_url);
                $enclosure->length = 0;
                // Marks this as a fallback thumbnail rather than a regular RSS
                // enclosure, distinguishable at HOOK_RENDER_ARTICLE_API time.
                $enclosure->title = 'og:thumbnail';
                $enclosure->width = $image_from_extraction ? 0 : ($og_data['image_width'] ?? 0);
                $enclosure->height = $image_from_extraction ? 0 : ($og_data['image_height'] ?? 0);

                if (!is_array($article['enclosures'])) {
                    $article['enclosures'] = [];
                }
                $article['enclosures'][] = $enclosure;

                $image_source_label = $image_from_extraction ? 'extracted image' : 'og:image';
                Debug::log("af_enhance_content: Added $image_source_label as enclosure: $image_url", Debug::LOG_VERBOSE);

                if ($image_from_extraction) {
                    $used_extraction = true;
                }
            }
        }

        // Set author if missing
        if (empty($article['author']) && !empty($og_data['author'])) {
            $article['author'] = $og_data['author'];
            if (!empty($og_data['author_is_twitter'])) {
                $article['author'] .= ' ' . self::TWITTER_AUTHOR_ICON;
            }
            Debug::log("af_enhance_content: Set author from og:article:author: " . $og_data['author'], Debug::LOG_VERBOSE);
        }

        // Enhance content with og:description if configured and content is
        // shorter. Falls back to a hand-rolled body-text extraction of the
        // already-fetched page when there's no og:description at all (some
        // sites, e.g. Wikipedia, ship no OG tags whatsoever) - no second
        // fetch, since $html is the same page fetched for OG extraction.
        $enhance_content = $this->host->get($this, "enhance_content", false);
        if ($enhance_content) {
            $summary = $og_data['description'] ?? '';
            $summary_source = 'og:description';

            if (empty($summary) && !empty($html)) {
                $extracted = $this->extract_body_summary($html);
                if (!empty($extracted)) {
                    $summary = $extracted;
                    $summary_source = 'extracted body text';
                }
            }

            if (!empty($summary)) {
                $content_length = strlen(strip_tags($article['content'] ?? ''));
                $summary_length = strlen($summary);

                if ($summary_length > $content_length) {
                    $article['content'] = '<p>' . htmlspecialchars($summary) . '</p>' .
                                          '<hr>' . ($article['content'] ?? '');
                    Debug::log("af_enhance_content: Enhanced content with $summary_source", Debug::LOG_VERBOSE);

                    if ($summary_source === 'extracted body text') {
                        $used_extraction = true;
                    }
                }
            }
        }

        // Mark the title with EXTRACTED_SUMMARY_ICON when either the image
        // or the summary above was assembled via body-page extraction
        // rather than pulled from clean og:* metadata, so it's visually
        // obvious in list and reader views that something was auto-derived
        // rather than provided by the site.
        if ($used_extraction && !empty($article['title'])
                && !str_ends_with($article['title'], self::EXTRACTED_SUMMARY_ICON)) {
            $article['title'] .= ' ' . self::EXTRACTED_SUMMARY_ICON;
        }

        // Log tags for potential future use
        if (!empty($og_data['tags'])) {
            Debug::log("af_enhance_content: Found tags: " . implode(', ', $og_data['tags']), Debug::LOG_VERBOSE);
        }

        return $article;
    }

    // =====================================================================
    // FEATURE 5: ENCLOSURE URL UPGRADING
    // =====================================================================

    private function upgrade_enclosure_urls($article, $html, $og_data = null) {
        if (!isset($article['enclosures']) || empty($article['enclosures'])) {
            return $article;
        }

        // Extract all img tags with srcset from the article page
        $page_images = $this->extract_page_images($html);

        if (empty($page_images)) {
            Debug::log("af_enhance_content: No images with srcset found on article page", Debug::LOG_VERBOSE);
        }

        $upgraded_count = 0;

        foreach ($article['enclosures'] as &$enclosure) {
            $type = $enclosure->type ?? '';

            // Only process image enclosures
            if (strpos($type, 'image/') !== 0) {
                continue;
            }

            $enclosure_url = $enclosure->link ?? '';
            if (empty($enclosure_url)) {
                continue;
            }

            $upgraded_url = null;

            // Try to match this enclosure to an image on the page
            if (!empty($page_images)) {
                $upgraded_url = $this->match_and_upgrade_url($enclosure_url, $page_images);
            }

            // Fallback: Try og:image if srcset matching failed
            if (!$upgraded_url && !empty($og_data['image'])) {
                if ($this->is_same_image_different_size($enclosure_url, $og_data['image'])) {
                    $upgraded_url = $og_data['image'];
                    Debug::log("af_enhance_content: Using og:image as fallback for enclosure upgrade", Debug::LOG_VERBOSE);
                }
            }

            if ($upgraded_url && $upgraded_url !== $enclosure_url) {
                Debug::log("af_enhance_content: Upgrading enclosure URL:\n  FROM: $enclosure_url\n  TO: $upgraded_url", Debug::LOG_VERBOSE);
                $enclosure->link = $upgraded_url;
                $upgraded_count++;
            }
        }

        if ($upgraded_count > 0) {
            Debug::log("af_enhance_content: Upgraded $upgraded_count enclosure(s) for article: " .
                ($article['title'] ?? 'unknown'), Debug::LOG_VERBOSE);
        }

        return $article;
    }

    private function extract_page_images($html) {
        $images = [];

        // Find all img tags
        preg_match_all('/<img\s+([^>]*?)>/is', $html, $img_matches);

        foreach ($img_matches[0] as $img_tag) {
            // Extract src
            $src = null;
            if (preg_match('/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $img_tag, $src_match)) {
                $src = $src_match[1];
            }

            // Extract srcset
            $srcset = null;
            $highest_res_url = null;
            if (preg_match('/srcset\s*=\s*["\']([^"\']+)["\']/i', $img_tag, $srcset_match)) {
                $srcset = $srcset_match[1];
                $highest_res_url = $this->extract_highest_res_from_srcset($srcset);
            }

            // Store both src and highest res from srcset
            if ($src || $highest_res_url) {
                $images[] = [
                    'src' => $src,
                    'highest_res' => $highest_res_url,
                    'srcset' => $srcset,
                ];
            }
        }

        return $images;
    }

    private function match_and_upgrade_url($enclosure_url, $page_images) {
        // Diagnostic: Log URL being matched
        Debug::log("AF_ENHANCE_CONTENT: Matching enclosure: " . $enclosure_url, Debug::LOG_VERBOSE);

        // Normalize the enclosure URL for comparison
        $enc_path = parse_url($enclosure_url, PHP_URL_PATH);
        if (!$enc_path) {
            Debug::log("AF_ENHANCE_CONTENT: No path found in enclosure URL");
            return null;
        }

        // Extract filename from enclosure
        $enc_filename = basename($enc_path);
        // Strip .webp suffix to handle image.png vs image.png.webp mismatches
        $enc_filename = preg_replace('/\.webp$/i', '', $enc_filename);
        $enc_filename_noext = pathinfo($enc_filename, PATHINFO_FILENAME);

        foreach ($page_images as $img) {
            // Try to match by comparing filenames or URL patterns
            $candidates = array_filter([$img['src'], $img['highest_res']]);

            foreach ($candidates as $candidate_url) {
                if (empty($candidate_url)) continue;

                $candidate_path = parse_url($candidate_url, PHP_URL_PATH);
                if (!$candidate_path) continue;

                $candidate_filename = basename($candidate_path);
                // Strip .webp suffix to handle image.png vs image.png.webp mismatches
                $candidate_filename = preg_replace('/\.webp$/i', '', $candidate_filename);
                $candidate_filename_noext = pathinfo($candidate_filename, PATHINFO_FILENAME);

                // Match by filename (with or without extension)
                if ($candidate_filename === $enc_filename ||
                    $candidate_filename_noext === $enc_filename_noext) {

                    // Return the highest res URL if available, otherwise src
                    $upgraded = $img['highest_res'] ?: $img['src'];
                    Debug::log("AF_ENHANCE_CONTENT: Found upgrade (filename match): " . $upgraded);
                    return $upgraded;
                }

                // Also check if the enclosure URL is a substring match (for CDN URLs with size parameters)
                // For example: /ws/240/image.jpg should match /ws/1024/image.jpg
                if ($this->is_same_image_different_size($enclosure_url, $candidate_url)) {
                    $upgraded = $img['highest_res'] ?: $img['src'];
                    Debug::log("AF_ENHANCE_CONTENT: Found upgrade (same image, different size): " . $upgraded);
                    return $upgraded;
                }
            }
        }

        // Diagnostic: No match found (non-verbose for visibility)
        Debug::log("AF_ENHANCE_CONTENT: No upgrade found for: " . $enclosure_url);
        return null;
    }

    private function is_same_image_different_size($url1, $url2) {
        // Check if two URLs point to the same image but with different size parameters
        // Common patterns:
        // - /ws/240/path/to/image.jpg vs /ws/1024/path/to/image.jpg (BBC)
        // - /resize/240x/path/to/image.jpg vs /resize/1024x/path/to/image.jpg
        // - /image.jpg?width=240 vs /image.jpg?width=1024
        // - image-300x200.jpg vs image-1024x768.jpg (WordPress)
        // - image-medium.jpg vs image-large.jpg (WordPress)
        // - image-scaled.jpg vs image.jpg (WordPress)
        // - image_thumb.jpg vs image_large.jpg (common CMS)

        $path1 = parse_url($url1, PHP_URL_PATH);
        $path2 = parse_url($url2, PHP_URL_PATH);

        if (!$path1 || !$path2) {
            return false;
        }

        // BBC-specific check: Compare last 2-3 path segments (directory/subdirectory/filename)
        // Example: /ace/ws/240/cpsprodpb/7324/live/image.jpg vs /news/1024/branded_mundo/7324/live/image.jpg
        // Both have 7324/live/image.jpg at the end
        $segments1 = explode('/', trim($path1, '/'));
        $segments2 = explode('/', trim($path2, '/'));

        if (count($segments1) >= 3 && count($segments2) >= 3) {
            // Get last 3 segments (directory/subdirectory/filename)
            $tail1 = implode('/', array_slice($segments1, -3));
            $tail2 = implode('/', array_slice($segments2, -3));

            if ($tail1 === $tail2) {
                return true;
            }

            // Also try last 2 segments (subdirectory/filename)
            $tail1 = implode('/', array_slice($segments1, -2));
            $tail2 = implode('/', array_slice($segments2, -2));

            if ($tail1 === $tail2) {
                return true;
            }
        }

        // Remove size parameters from paths (BBC style /ws/240/)
        $normalized1 = preg_replace('/\/\d+[wx]?\//i', '/', $path1);
        $normalized2 = preg_replace('/\/\d+[wx]?\//i', '/', $path2);

        // Handle _w240.jpg vs _w1024.jpg patterns
        $normalized1 = preg_replace('/_w\d+\./i', '.', $normalized1);
        $normalized2 = preg_replace('/_w\d+\./i', '.', $normalized2);

        // Handle WordPress dimension suffixes: -300x200.jpg, -1024x768.jpg
        $normalized1 = preg_replace('/-\d+x\d+(?=\.[^.]+$)/i', '', $normalized1);
        $normalized2 = preg_replace('/-\d+x\d+(?=\.[^.]+$)/i', '', $normalized2);

        // Handle WordPress size names: -thumbnail, -medium, -large, -scaled, -default, -lede
        $normalized1 = preg_replace('/-(?:thumbnail|thumb|small|medium|large|xlarge|xxlarge|scaled|full|default|lede)(?=\.[^.]+$)/i', '', $normalized1);
        $normalized2 = preg_replace('/-(?:thumbnail|thumb|small|medium|large|xlarge|xxlarge|scaled|full|default|lede)(?=\.[^.]+$)/i', '', $normalized2);

        // Handle underscore variants: _thumb, _small, _large, _default, _lede
        $normalized1 = preg_replace('/_(?:thumbnail|thumb|small|medium|large|xlarge|xxlarge|scaled|full|default|lede)(?=\.[^.]+$)/i', '', $normalized1);
        $normalized2 = preg_replace('/_(?:thumbnail|thumb|small|medium|large|xlarge|xxlarge|scaled|full|default|lede)(?=\.[^.]+$)/i', '', $normalized2);

        // Handle numeric suffixes: -1.jpg, -2.jpg
        $normalized1 = preg_replace('/-\d+(?=\.[^.]+$)/i', '', $normalized1);
        $normalized2 = preg_replace('/-\d+(?=\.[^.]+$)/i', '', $normalized2);

        return $normalized1 === $normalized2;
    }

    // =====================================================================
    // HELPER METHODS
    // =====================================================================

    // Threshold below which an article is considered to need a content
    // backfill (og:description or extracted body text). Deliberately
    // independent of article_has_images() - see the comment at the
    // should_fetch check in hook_article_filter().
    private const MEANINGFUL_CONTENT_MIN_CHARS = 250;

    private function article_has_meaningful_content($article) {
        $text = trim(strip_tags($article['content'] ?? ''));
        return strlen($text) >= self::MEANINGFUL_CONTENT_MIN_CHARS;
    }

    private function article_has_images($article) {
        // Check for image enclosures
        if (!empty($article['enclosures'])) {
            foreach ($article['enclosures'] as $enc) {
                $type = $enc->type ?? '';
                if (strpos($type, 'image/') === 0) {
                    return true;
                }
            }
        }

        // Check for inline <img> tags, ignoring small icon-sized images (e.g.
        // social share buttons some themes/plugins inject into post content) —
        // those shouldn't count as "the article already has a real image" and
        // block the og:image fallback below.
        $content = $article['content'] ?? '';
        if (preg_match_all('/<img\s+[^>]*>/i', $content, $matches)) {
            foreach ($matches[0] as $img_tag) {
                if (!$this->is_icon_sized_image($img_tag)) {
                    return true;
                }
            }
        }

        return false;
    }

    // Like article_has_images() but only checks enclosures, not inline <img> tags.
    // Used for the og:image fallback trigger so feeds with inline images but no
    // enclosures still get an og:thumbnail for list-view display in API clients.
    private function article_has_enclosure_images($article) {
        if (!empty($article['enclosures'])) {
            foreach ($article['enclosures'] as $enc) {
                $type = $enc->type ?? '';
                if (strpos($type, 'image/') === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    private function infer_mime_type($url) {
        // Handle data URLs: data:image/png;base64,...
        if (strpos($url, 'data:') === 0) {
            if (preg_match('/^data:([^;,]+)/', $url, $match)) {
                return $match[1];
            }
            return 'image/jpeg';
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return 'image/jpeg';
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $mime_types = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'avif' => 'image/avif',
            'bmp' => 'image/bmp',
            'ico' => 'image/x-icon',
            'mp3' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'wav' => 'audio/wav',
            'm4a' => 'audio/mp4',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mkv' => 'video/x-matroska',
            'avi' => 'video/x-msvideo',
            'mov' => 'video/quicktime',
        ];

        return $mime_types[$extension] ?? 'image/jpeg';
    }

    public function api_version() {
        return 2;
    }
}
