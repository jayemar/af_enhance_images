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
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
    }

    // =====================================================================
    // CONFIGURATION UI
    // =====================================================================

    public function hook_prefs_tab($args) {
        if ($args != "prefFeeds") return;

        $inline_enhancement = $this->host->get($this, "inline_enhancement", true);
        $fix_enclosure_type = $this->host->get($this, "fix_enclosure_type", true);
        $extract_og = $this->host->get($this, "extract_og", false);
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
                        <?= __('Add og:image as enclosure, set author from og:article:author. Fetches article page only when RSS feed lacks images.') ?>
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
        // Feature 1: Enhance inline images
        if ($this->host->get($this, "inline_enhancement", true)) {
            $article = $this->enhance_inline_images($article);
        }

        // Feature 2: Fix enclosure MIME types
        if ($this->host->get($this, "fix_enclosure_type", true)) {
            $article = $this->fix_enclosure_types($article);
        }

        // Feature 3 & 4 & 5: Article page fetching for OG and enclosure upgrading
        $extract_og = $this->host->get($this, "extract_og", false);
        $upgrade_enclosures = $this->host->get($this, "upgrade_enclosures", false);

        // Determine if we need to fetch the article page
        $should_fetch = false;

        // Fetch if OG extraction enabled and article lacks images
        if ($extract_og && !$this->article_has_images($article)) {
            $should_fetch = true;
        }

        // Fetch if enclosure upgrading enabled and article has enclosures
        if ($upgrade_enclosures && !empty($article['enclosures'])) {
            $should_fetch = true;
            Debug::log("af_enhance_images: Will fetch page for enclosure upgrading (count: " .
                count($article['enclosures']) . ")", Debug::LOG_VERBOSE);
        }

        if ($should_fetch) {
            $url = $article['link'] ?? '';
            if (!empty($url)) {
                Debug::log("af_enhance_images: Fetching article page: $url", Debug::LOG_VERBOSE);
                $html = $this->fetch_article_page($url);

                if ($html) {
                    // Extract OG metadata if enabled
                    if ($extract_og) {
                        $og_data = $this->extract_og_metadata($html);
                        if ($og_data) {
                            $article = $this->apply_og_metadata($article, $og_data);
                        }
                    }

                    // Upgrade enclosure URLs if enabled
                    if ($upgrade_enclosures && !empty($article['enclosures'])) {
                        $article = $this->upgrade_enclosure_urls($article, $html);
                    }
                }
            }
        }

        return $article;
    }

    /**
     * Hook: Ensure content is never null before API response processing
     * This prevents TypeError in DiskCache::rewrite_urls()
     */
    public function hook_render_article_api($row) {
        // Detect wrapper structure
        $is_headline = isset($row['headline']);
        $article = $is_headline ? $row['headline'] : ($row['article'] ?? $row);

        // Ensure content is never null (prevents TypeError)
        if (!isset($article['content']) || $article['content'] === null) {
            $article['content'] = '';

            Debug::log("af_enhance_images: Fixed null/missing content for article: " .
                ($article['title'] ?? 'unknown'), Debug::LOG_VERBOSE);
        }

        // Return with proper wrapper structure preserved
        if ($is_headline) {
            $row['headline'] = $article;
            return $row;
        } elseif (isset($row['article'])) {
            $row['article'] = $article;
            return $row;
        } else {
            return $article;
        }
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

        // Process all img tags
        $content = preg_replace_callback(
            '/<img\s+([^>]*?)>/is',
            function($matches) use (&$modifications) {
                return $this->enhance_img_tag($matches[0], $modifications);
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

    private function enhance_img_tag($img_tag, &$modifications) {
        $original = $img_tag;

        // Step 1: Handle lazy loading - convert data-src to src
        if (preg_match('/data-src\s*=\s*["\']([^"\']+)["\']/i', $img_tag, $data_src_match)) {
            if (!preg_match('/\ssrc\s*=\s*["\'][^"\']+["\']/i', $img_tag)) {
                $img_tag = preg_replace(
                    '/data-src\s*=/i',
                    'src=',
                    $img_tag,
                    1
                );
                $modifications[] = 'data-src->src';
            }
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

        return $img_tag;
    }

    private function extract_highest_res_from_srcset($srcset) {
        $sources = array_map('trim', explode(',', $srcset));

        $highest_width = 0;
        $highest_url = null;

        foreach ($sources as $source) {
            if (preg_match('/^(.+?)\s+(\d+(?:\.\d+)?)(w|x)$/i', $source, $match)) {
                $url = trim($match[1]);
                $value = floatval($match[2]);
                $descriptor = strtolower($match[3]);

                $comparable_width = ($descriptor === 'w') ? $value : $value * 1000;

                if ($comparable_width > $highest_width) {
                    $highest_width = $comparable_width;
                    $highest_url = $url;
                }
            } elseif (trim($source) !== '') {
                if ($highest_url === null) {
                    $highest_url = trim($source);
                }
            }
        }

        return $highest_url;
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

            if (empty($type)) {
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
                $enclosure->title = $og_data['image_alt'] ?? 'Featured image';

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

    private function upgrade_enclosure_urls($article, $html) {
        if (!isset($article['enclosures']) || empty($article['enclosures'])) {
            return $article;
        }

        // Extract all img tags with srcset from the article page
        $page_images = $this->extract_page_images($html);

        if (empty($page_images)) {
            Debug::log("af_enhance_images: No images with srcset found on article page", Debug::LOG_VERBOSE);
            return $article;
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

            // Try to match this enclosure to an image on the page
            $upgraded_url = $this->match_and_upgrade_url($enclosure_url, $page_images);

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
        // Normalize the enclosure URL for comparison
        $enc_path = parse_url($enclosure_url, PHP_URL_PATH);
        if (!$enc_path) {
            return null;
        }

        // Extract filename from enclosure
        $enc_filename = basename($enc_path);
        $enc_filename_noext = pathinfo($enc_filename, PATHINFO_FILENAME);

        foreach ($page_images as $img) {
            // Try to match by comparing filenames or URL patterns
            $candidates = array_filter([$img['src'], $img['highest_res']]);

            foreach ($candidates as $candidate_url) {
                if (empty($candidate_url)) continue;

                $candidate_path = parse_url($candidate_url, PHP_URL_PATH);
                if (!$candidate_path) continue;

                $candidate_filename = basename($candidate_path);
                $candidate_filename_noext = pathinfo($candidate_filename, PATHINFO_FILENAME);

                // Match by filename (with or without extension)
                if ($candidate_filename === $enc_filename ||
                    $candidate_filename_noext === $enc_filename_noext) {

                    // Return the highest res URL if available, otherwise src
                    return $img['highest_res'] ?: $img['src'];
                }

                // Also check if the enclosure URL is a substring match (for CDN URLs with size parameters)
                // For example: /ws/240/image.jpg should match /ws/1024/image.jpg
                if ($this->is_same_image_different_size($enclosure_url, $candidate_url)) {
                    return $img['highest_res'] ?: $img['src'];
                }
            }
        }

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

        // Remove size parameters from paths (BBC style /ws/240/)
        $normalized1 = preg_replace('/\/\d+[wx]?\//i', '/', $path1);
        $normalized2 = preg_replace('/\/\d+[wx]?\//i', '/', $path2);

        // Handle _w240.jpg vs _w1024.jpg patterns
        $normalized1 = preg_replace('/_w\d+\./i', '.', $normalized1);
        $normalized2 = preg_replace('/_w\d+\./i', '.', $normalized2);

        // Handle WordPress dimension suffixes: -300x200.jpg, -1024x768.jpg
        $normalized1 = preg_replace('/-\d+x\d+(?=\.[^.]+$)/i', '', $normalized1);
        $normalized2 = preg_replace('/-\d+x\d+(?=\.[^.]+$)/i', '', $normalized2);

        // Handle WordPress size names: -thumbnail, -medium, -large, -scaled
        $normalized1 = preg_replace('/-(?:thumbnail|thumb|small|medium|large|xlarge|xxlarge|scaled|full)(?=\.[^.]+$)/i', '', $normalized1);
        $normalized2 = preg_replace('/-(?:thumbnail|thumb|small|medium|large|xlarge|xxlarge|scaled|full)(?=\.[^.]+$)/i', '', $normalized2);

        // Handle underscore variants: _thumb, _small, _large
        $normalized1 = preg_replace('/_(?:thumbnail|thumb|small|medium|large|xlarge|xxlarge|scaled|full)(?=\.[^.]+$)/i', '', $normalized1);
        $normalized2 = preg_replace('/_(?:thumbnail|thumb|small|medium|large|xlarge|xxlarge|scaled|full)(?=\.[^.]+$)/i', '', $normalized2);

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

        // Check for inline <img> tags
        $content = $article['content'] ?? '';
        if (preg_match('/<img\s/i', $content)) {
            return true;
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
