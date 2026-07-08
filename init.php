<?php
/**
 * af_enhance_images - Comprehensive image enhancement for RSS feeds
 *
 * This plugin provides complete image handling for TT-RSS:
 * 1. Inline image enhancement (srcset, lazy loading)
 * 2. Enclosure MIME type fixing
 * 3. Open Graph metadata extraction
 * 4. Enclosure URL upgrading from article pages
 *
 * Features:
 * - Rewrite img src to use highest resolution from srcset
 * - Convert data-src to src for lazy-loaded images
 * - Remove loading="lazy" attributes
 * - Fix empty enclosure content_type
 * - Fetch article pages and extract OG metadata
 * - Add og:image as enclosure
 * - Set author from og:article:author
 * - Enhance content with og:description
 * - Upgrade low-resolution enclosure URLs by fetching article page and extracting high-res URLs from srcset
 *
 * Installation:
 * 1. Copy this directory to plugins.local/af_enhance_images/
 * 2. Enable the plugin in Preferences -> Plugins
 * 3. Configure in Preferences -> Feeds -> Image Enhancement
 *
 * Version: 2.0
 * Author: jayemar
 */
class Af_Enhance_Images extends Plugin {

    private $host;

    public function about() {
        return array(
            2.0,
            "Comprehensive image enhancement: srcset, lazy loading, enclosure types, Open Graph, and enclosure URL upgrading",
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
        Debug::log("AF_ENHANCE_IMAGES: Plugin initialized successfully");
    }

    // =====================================================================
    // CONFIGURATION UI
    // =====================================================================

    public function hook_prefs_tab($args) {
        if ($args != "prefFeeds") return;

        $inline_enhancement = $this->host->get($this, "inline_enhancement", true);
        $strip_tracking_pixels = $this->host->get($this, "strip_tracking_pixels", true);
        $fix_enclosure_type = $this->host->get($this, "fix_enclosure_type", true);
        $extract_og = $this->host->get($this, "extract_og", true);
        $enhance_content = $this->host->get($this, "enhance_content", false);
        $upgrade_enclosures = $this->host->get($this, "upgrade_enclosures", false);
        ?>
        <div dojoType="dijit.layout.AccordionPane"
            title="<i class='material-icons'>image</i> <?= __('Image Enhancement') ?>">

            <form dojoType="dijit.form.Form">

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
                    <legend><?= __('Inline Image Enhancement') ?></legend>
                    <label class="checkbox">
                        <input dojoType="dijit.form.CheckBox" type="checkbox" name="inline_enhancement" value="1"
                            <?= $inline_enhancement ? 'checked' : '' ?>>
                        <?= __('Enhance inline images (srcset, lazy loading)') ?>
                    </label>
                    <p class="help-text" style="margin-left: 24px; color: #666;">
                        <?= __('Extract highest resolution from srcset, convert data-src to src, remove loading=lazy') ?>
                    </p>
                </fieldset>

                <fieldset>
                    <legend><?= __('Tracking Pixel Removal') ?></legend>
                    <label class="checkbox">
                        <input dojoType="dijit.form.CheckBox" type="checkbox" name="strip_tracking_pixels" value="1"
                            <?= $strip_tracking_pixels ? 'checked' : '' ?>>
                        <?= __('Strip tracking pixels from article content') ?>
                    </label>
                    <p class="help-text" style="margin-left: 24px; color: #666;">
                        <?= __('Removes invisible images declared 2px or smaller in both width and height, commonly used to detect when an article was opened') ?>
                    </p>
                </fieldset>

                <fieldset>
                    <legend><?= __('Enclosure Type Fixing') ?></legend>
                    <label class="checkbox">
                        <input dojoType="dijit.form.CheckBox" type="checkbox" name="fix_enclosure_type" value="1"
                            <?= $fix_enclosure_type ? 'checked' : '' ?>>
                        <?= __('Fix empty enclosure content types') ?>
                    </label>
                    <p class="help-text" style="margin-left: 24px; color: #666;">
                        <?= __('Infer MIME type from URL extension when content_type is empty') ?>
                    </p>
                </fieldset>

                <fieldset>
                    <legend><?= __('Open Graph Metadata') ?></legend>
                    <label class="checkbox">
                        <input dojoType="dijit.form.CheckBox" type="checkbox" name="extract_og" value="1"
                            <?= $extract_og ? 'checked' : '' ?>>
                        <?= __('Extract Open Graph metadata') ?>
                    </label>
                    <p class="help-text" style="margin-left: 24px; color: #666;">
                        <?= __('Add og:image as thumbnail enclosure, set author from og:article:author. Fetches article page only when RSS feed has no image enclosures.') ?>
                    </p>

                    <label class="checkbox" style="margin-left: 24px;">
                        <input dojoType="dijit.form.CheckBox" type="checkbox" name="enhance_content" value="1"
                            <?= $enhance_content ? 'checked' : '' ?>>
                        <?= __('Use og:description for short content') ?>
                    </label>
                </fieldset>

                <fieldset>
                    <legend><?= __('Enclosure URL Upgrading') ?></legend>
                    <label class="checkbox">
                        <input dojoType="dijit.form.CheckBox" type="checkbox" name="upgrade_enclosures" value="1"
                            <?= $upgrade_enclosures ? 'checked' : '' ?>>
                        <?= __('Upgrade enclosure URLs from article page') ?>
                    </label>
                    <p class="help-text" style="margin-left: 24px; color: #666;">
                        <?= __('Fetches article page when enclosures exist and extracts high-resolution image URLs from srcset to replace low-res enclosures') ?>
                    </p>
                </fieldset>

                <hr>

                <?= \Controls\submit_tag(__("Save")) ?>
            </form>

            <hr>
            <h3><?= __('Feature Summary') ?></h3>
            <ul>
                <li><strong>Inline Enhancement:</strong> <?= __('Processes <img> tags in article content') ?></li>
                <li><strong>Tracking Pixel Removal:</strong> <?= __('Removes invisible beacon images used for open-tracking') ?></li>
                <li><strong>Type Fixing:</strong> <?= __('Fixes empty MIME types in enclosures') ?></li>
                <li><strong>OG Extraction:</strong> <?= __('Extracts og:image, author, description from article pages') ?></li>
                <li><strong>Enclosure Upgrading:</strong> <?= __('Replaces low-res enclosure URLs with high-res from article srcset') ?></li>
            </ul>
        </div>
        <?php
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

        echo __('Settings saved.');
    }

    // =====================================================================
    // MAIN ARTICLE FILTER HOOK
    // =====================================================================

    public function hook_article_filter($article) {
        // Diagnostic: Confirm hook is being called (non-verbose for visibility)
        Debug::log("AF_ENHANCE_IMAGES: hook_article_filter() called for: " .
            ($article['title'] ?? 'unknown'));

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

        // Fetch if OG extraction enabled and article lacks images (both enclosures and inline).
        // Uses article_has_images() so feeds with full inline content (NPR, The Verge) do NOT
        // get an og:thumbnail - that would duplicate the inline images already in the content.
        $article_for_has_images_check = ['content' => $original_content, 'enclosures' => $article['enclosures'] ?? []];
        if ($extract_og && !$this->article_has_images($article_for_has_images_check)) {
            $should_fetch = true;
        }

        // Fetch if enclosure upgrading enabled and article has enclosures
        if ($upgrade_enclosures && !empty($article['enclosures'])) {
            $should_fetch = true;
            // Diagnostic: Confirm enclosure upgrading decision (non-verbose for visibility)
            Debug::log("AF_ENHANCE_IMAGES: Will attempt to upgrade " .
                count($article['enclosures']) . " enclosure(s)");
        }

        if ($should_fetch) {
            $url = $article['link'] ?? '';
            if (!empty($url)) {
                Debug::log("af_enhance_images: Fetching article page: $url", Debug::LOG_VERBOSE);
                $html = $this->fetch_article_page($url);

                if ($html) {
                    // Extract OG metadata (needed for both og extraction and enclosure upgrading fallback)
                    $og_data = null;
                    if ($extract_og || $upgrade_enclosures) {
                        $og_data = $this->extract_og_metadata($html);
                    }

                    // Apply OG metadata if extraction is enabled
                    if ($extract_og && $og_data) {
                        $article = $this->apply_og_metadata($article, $og_data);
                    }

                    // Upgrade enclosure URLs if enabled
                    if ($upgrade_enclosures && !empty($article['enclosures'])) {
                        $article = $this->upgrade_enclosure_urls($article, $html, $og_data);
                    }
                }
            }
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

            Debug::log("af_enhance_images: Fixed null/missing content for article: " .
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
            Debug::log("af_enhance_images: Enhanced article: " .
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

    private function extract_highest_res_from_srcset($srcset) {
        $sources = array_map('trim', explode(',', $srcset));

        $best_over_width = PHP_FLOAT_MAX;  // smallest candidate >= target
        $best_over_url = null;
        $best_under_width = 0;             // largest candidate < target (fallback)
        $best_under_url = null;
        $first_bare_url = null;            // descriptor-less entry (last resort)

        foreach ($sources as $source) {
            if (preg_match('/^(.+?)\s+(\d+(?:\.\d+)?)(w|x)$/i', $source, $match)) {
                $url = trim($match[1]);
                $value = floatval($match[2]);
                $descriptor = strtolower($match[3]);

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
            } elseif (trim($source) !== '') {
                if ($first_bare_url === null) {
                    $first_bare_url = trim($source);
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

                        Debug::log("af_enhance_images: Set type to '$inferred_type' for: $url",
                            Debug::LOG_VERBOSE);
                    }
                }
            }
        }

        if ($modified) {
            Debug::log("af_enhance_images: Fixed enclosure types for article: " .
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
            Debug::log("af_enhance_images: Failed to fetch: $url (may be blocked by site)", Debug::LOG_VERBOSE);
            return null;
        }

        $content_length = strlen($response);
        Debug::log("af_enhance_images: Successfully fetched $content_length bytes from: $url", Debug::LOG_VERBOSE);

        return $response;
    }

    // =====================================================================
    // FEATURE 4: OPEN GRAPH METADATA EXTRACTION
    // =====================================================================

    private function extract_og_metadata($html) {
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
            'tags' => [],
            'title' => null,
            'site_name' => null,
            'type' => null,
            'published_time' => null,
        ];

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
                    $og_data['description'] = html_entity_decode($content);
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
                    $og_data['author'] = html_entity_decode($content);
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
                    if (empty($og_data['description'])) {
                        $og_data['description'] = html_entity_decode($content);
                    }
                    break;
                case 'twitter:creator':
                case 'twitter:site':
                    if (empty($og_data['author'])) {
                        $og_data['author'] = html_entity_decode($content);
                    }
                    break;
            }
        }

        // Return null only if we found absolutely nothing useful
        // Tags can be useful even without image/description/author
        if (empty($og_data['image']) && empty($og_data['description']) && empty($og_data['author']) && empty($og_data['tags'])) {
            return null;
        }

        return $og_data;
    }

    private function apply_og_metadata($article, $og_data) {
        // Add og:image as enclosure if we don't have image enclosures
        if (!empty($og_data['image'])) {
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
                $enclosure->link = $og_data['image'];
                $enclosure->type = $this->infer_mime_type($og_data['image']);
                $enclosure->length = 0;
                // Use 'og:thumbnail' as a marker so af_filter_enclosures can distinguish
                // this fallback thumbnail from regular RSS enclosures. The title field is
                // available at HOOK_RENDER_ARTICLE_API time but stripped from FreshAPI output.
                $enclosure->title = 'og:thumbnail';
                $enclosure->width = $og_data['image_width'] ?? 0;
                $enclosure->height = $og_data['image_height'] ?? 0;

                if (!is_array($article['enclosures'])) {
                    $article['enclosures'] = [];
                }
                $article['enclosures'][] = $enclosure;

                Debug::log("af_enhance_images: Added og:image as enclosure: " . $og_data['image'], Debug::LOG_VERBOSE);
            }
        }

        // Set author if missing
        if (empty($article['author']) && !empty($og_data['author'])) {
            $article['author'] = $og_data['author'];
            Debug::log("af_enhance_images: Set author from og:article:author: " . $og_data['author'], Debug::LOG_VERBOSE);
        }

        // Enhance content with og:description if configured and content is shorter
        $enhance_content = $this->host->get($this, "enhance_content", false);
        if ($enhance_content && !empty($og_data['description'])) {
            $content_length = strlen(strip_tags($article['content'] ?? ''));
            $og_desc_length = strlen($og_data['description']);

            if ($og_desc_length > $content_length) {
                $article['content'] = '<p>' . htmlspecialchars($og_data['description']) . '</p>' .
                                      '<hr>' . ($article['content'] ?? '');
                Debug::log("af_enhance_images: Enhanced content with og:description", Debug::LOG_VERBOSE);
            }
        }

        // Log tags for potential future use
        if (!empty($og_data['tags'])) {
            Debug::log("af_enhance_images: Found tags: " . implode(', ', $og_data['tags']), Debug::LOG_VERBOSE);
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
            Debug::log("af_enhance_images: No images with srcset found on article page", Debug::LOG_VERBOSE);
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
                    Debug::log("af_enhance_images: Using og:image as fallback for enclosure upgrade", Debug::LOG_VERBOSE);
                }
            }

            if ($upgraded_url && $upgraded_url !== $enclosure_url) {
                Debug::log("af_enhance_images: Upgrading enclosure URL:\n  FROM: $enclosure_url\n  TO: $upgraded_url", Debug::LOG_VERBOSE);
                $enclosure->link = $upgraded_url;
                $upgraded_count++;
            }
        }

        if ($upgraded_count > 0) {
            Debug::log("af_enhance_images: Upgraded $upgraded_count enclosure(s) for article: " .
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
        // Diagnostic: Log URL being matched (non-verbose for visibility)
        Debug::log("AF_ENHANCE_IMAGES: Matching enclosure: " . $enclosure_url);

        // Normalize the enclosure URL for comparison
        $enc_path = parse_url($enclosure_url, PHP_URL_PATH);
        if (!$enc_path) {
            Debug::log("AF_ENHANCE_IMAGES: No path found in enclosure URL");
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
                    Debug::log("AF_ENHANCE_IMAGES: Found upgrade (filename match): " . $upgraded);
                    return $upgraded;
                }

                // Also check if the enclosure URL is a substring match (for CDN URLs with size parameters)
                // For example: /ws/240/image.jpg should match /ws/1024/image.jpg
                if ($this->is_same_image_different_size($enclosure_url, $candidate_url)) {
                    $upgraded = $img['highest_res'] ?: $img['src'];
                    Debug::log("AF_ENHANCE_IMAGES: Found upgrade (same image, different size): " . $upgraded);
                    return $upgraded;
                }
            }
        }

        // Diagnostic: No match found (non-verbose for visibility)
        Debug::log("AF_ENHANCE_IMAGES: No upgrade found for: " . $enclosure_url);
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
