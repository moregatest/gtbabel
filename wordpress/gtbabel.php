<?php
/**
 * Plugin Name: Gtbabel
 * Plugin URI: https://github.com/vielhuber/gtbabel
 * Description: Instant server-side translation of any page.
 * Version: 1.1.2
 * Author: David Vielhuber
 * Author URI: https://vielhuber.de
 * License: free
 */
if (file_exists(__DIR__ . '/vendor/scoper-autoload.php')) {
    require_once __DIR__ . '/vendor/scoper-autoload.php';
} else {
    require_once __DIR__ . '/vendor/autoload.php';
}
use vielhuber\gtbabel\Gtbabel;
use vielhuber\stringhelper\__;

class GtbabelWordPress
{
    private $gtbabel;

    public function __construct($gtbabel)
    {
        $this->gtbabel = $gtbabel;
        $this->installHook();
        $this->localizePlugin();
        $this->initBackend();
        $this->triggerPreventPublish();
        $this->addTopBarItem();
        $this->disableAutoRedirect();
        $this->localizeJs();
        $this->setDefaultSettingsToOption();
        $this->startHook();
        $this->stopHook();
    }

    private function disableAutoRedirect()
    {
        remove_action('template_redirect', 'redirect_canonical');
    }

    private function localizeJs()
    {
        $settings = get_option('gtbabel_settings');
        if (!empty($settings['localize_js'])) {
            add_action(
                'wp_head',
                function () use ($settings) {
                    $this->gtbabel->dom->outputJsLocalizationHelper($settings['localize_js']);
                },
                -1
            );
        }
    }

    private function startHook()
    {
        add_action('after_setup_theme', function () {
            $this->start();
        });
    }

    private function stopHook()
    {
        add_action(
            'shutdown',
            function () {
                $this->stop();
            },
            0
        );
    }

    private function start()
    {
        $settings = get_option('gtbabel_settings');
        // dynamically changed settings
        $settings['prevent_publish'] = !is_user_logged_in();
        $this->gtbabel->start($settings);
    }

    private function stop()
    {
        $this->gtbabel->stop();
    }

    private function reset()
    {
        $this->gtbabel->reset();
    }

    private function installHook()
    {
        register_activation_hook(__FILE__, function () {
            $this->setDefaultSettingsToOption();
        });
    }

    private function localizePlugin()
    {
        add_action('plugins_loaded', function () {
            load_plugin_textdomain('gtbabel-plugin', false, dirname(plugin_basename(__FILE__)) . '/languages');
        });
    }

    private function setDefaultSettingsToOption()
    {
        if (get_option('gtbabel_settings') === false || get_option('gtbabel_settings') == '') {
            $lng_source = mb_strtolower(mb_substr(get_locale(), 0, 2));
            $languages = ['de', 'en'];
            if (!in_array($lng_source, $languages)) {
                $languages[] = $lng_source;
            }
            delete_option('gtbabel_settings'); // this is needed, because sometimes the option exists (with the value '')
            add_option(
                'gtbabel_settings',
                $this->gtbabel->settings->setupSettings([
                    'languages' => $languages,
                    'lng_source' => $lng_source,
                    'lng_folder' => '/wp-content/plugins/gtbabel/locales',
                    'log_folder' => '/wp-content/plugins/gtbabel/logs',
                    'exclude_urls' => ['/wp-admin', 'wp-login.php', 'wp-cron.php', 'wp-comments-post.php'],
                    'exclude_dom' => ['.notranslate', '.lngpicker', '#wpadminbar']
                ])
            );
        }
    }

    private function triggerPreventPublish()
    {
        add_action(
            'post_updated',
            function ($post_ID, $post_after, $post_before) {
                $post_before_status = get_post_status($post_before);
                $post_after_status = get_post_status($post_after);
                $post_before_url = get_permalink($post_before);
                $post_after_url = get_permalink($post_after);
                if (
                    $post_after_status === 'trash' ||
                    $post_after_status === 'draft' ||
                    $post_before_url != $post_after_url
                ) {
                    // remove trashed
                    if ($post_after_status === 'trash') {
                        $this->gtbabel->publish->edit($post_after_url, []);
                    }
                    // by default any post has prevent publish on for all languages
                    if ($post_after_status === 'draft') {
                        $this->gtbabel->publish->edit(
                            $post_after_url,
                            $this->gtbabel->settings->getSelectedLanguageCodesWithoutSource()
                        );
                    }
                    // if a slug is changed, preserve settings
                    if ($post_before_url != $post_after_url) {
                        $this->gtbabel->publish->change($post_before_url, $post_after_url);
                    }
                    $this->changeSetting('prevent_publish_urls', $this->gtbabel->settings->get('prevent_publish_urls'));
                }
            },
            10,
            3
        );
    }

    private function addTopBarItem()
    {
        add_action(
            'admin_bar_menu',
            function ($admin_bar) {
                global $pagenow;
                // we always add the menu item (in case of ajax url requests we can replace it easily)
                $html = '<span class="ab-icon"></span>' . __('Translate now', 'gtbabel-plugin');
                if ($pagenow == 'post.php') {
                    $url = get_permalink((int) $_GET['post']);
                    if ($url == '') {
                        $html = '';
                    }
                } elseif ($pagenow == 'term.php') {
                    $url = get_term_link((int) $_GET['tag_ID'], $_GET['taxonomy']);
                    if ($url == '') {
                        $html = '';
                    }
                } else {
                    $html = '';
                }

                $admin_bar->add_menu([
                    'id' => 'gtbabel-translate',
                    'parent' => null,
                    'group' => null,
                    'title' => $html,
                    'href' => admin_url('admin.php?page=gtbabel-trans&url=' . urlencode($url)),
                    'meta' => ['target' => '_blank']
                ]);
            },
            500
        );
        add_action(
            'admin_head',
            function () {
                ?>
                <style>
                /* add icon */
                #wpadminbar #wp-admin-bar-gtbabel-translate .ab-icon:before { content: "\f11f"; top: 3px; }
                /* hide placeholder */
                #wpadminbar #wp-admin-bar-gtbabel-translate a:empty { display:none; }
                </style>
                <script>
                /* reflect url changes in gutenberg */
                document.addEventListener('DOMContentLoaded', function() {
                    if( wp !== undefined && wp.data !== undefined ) {
                        if( window.location.href.indexOf('post-new.php') > -1 || window.location.href.indexOf('post.php') > -1 ) {
                            let prev_status = wp.data.select('core/editor').getEditedPostAttribute('status'),
                                prev_permalink = wp.data.select('core/editor').getPermalink();
                            wp.data.subscribe(function () {
                                let isSavingPost = wp.data.select('core/editor').isSavingPost(),
                                    isAutosavingPost = wp.data.select('core/editor').isAutosavingPost();
                                if (isSavingPost && !isAutosavingPost) {
                                    let cur_status = wp.data.select('core/editor').getEditedPostAttribute('status'),
                                        cur_permalink = wp.data.select('core/editor').getPermalink();
                                    let skip = false;
                                    if( prev_status === cur_status && prev_permalink === cur_permalink ) { skip = true; }
                                    prev_status = cur_status;
                                    prev_permalink = cur_permalink;
                                    if( skip === true ) { return; }
                                    fetch(window.location.href).then(v=>v.text()).catch(v=>v).then(data => {
                                        let dom = new DOMParser().parseFromString(data, 'text/html').querySelector('#wp-admin-bar-gtbabel-translate');
                                        if( dom !== null ) {
                                            document.querySelector('#wp-admin-bar-gtbabel-translate').innerHTML = dom.innerHTML;
                                        }
                                    }); 
                                }
                            });
                        }
                    }
                });
                </script>
                <?php
            },
            100
        );
    }

    private function initBackend()
    {
        add_action('admin_menu', function () {
            $menus = [];

            $menu = add_menu_page(
                'Gtbabel',
                'Gtbabel',
                'manage_options',
                'gtbabel-settings',
                function () {
                    $this->initBackendSettings();
                },
                'dashicons-admin-site-alt3',
                100
            );
            $menus[] = $menu;

            add_submenu_page(
                'gtbabel-settings',
                __('Settings', 'gtbabel-plugin'),
                __('Settings', 'gtbabel-plugin'),
                'manage_options',
                'gtbabel-settings'
            );

            $submenu = add_submenu_page(
                'gtbabel-settings',
                __('String translations', 'gtbabel-plugin'),
                __('String translations', 'gtbabel-plugin'),
                'manage_options',
                'gtbabel-trans',
                function () {
                    $this->initBackendStringTranslation();
                }
            );
            $menus[] = $submenu;

            $submenu = add_submenu_page(
                'gtbabel-settings',
                __('Translation services', 'gtbabel-plugin'),
                __('Translation services', 'gtbabel-plugin'),
                'manage_options',
                'gtbabel-services',
                function () {
                    $this->initBackendTranslationServices();
                }
            );
            $menus[] = $submenu;

            foreach ($menus as $menus__value) {
                add_action('admin_print_styles-' . $menus__value, function () {
                    wp_enqueue_style('gtbabel-css', plugins_url('gtbabel.css', __FILE__));
                });
                add_action('admin_print_scripts-' . $menus__value, function () {
                    wp_enqueue_script('gtbabel-js', plugins_url('gtbabel.js', __FILE__));
                });
            }
        });
    }

    private function initBackendSettings()
    {
        $message = '';

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            if (isset($_POST['save_settings'])) {
                $settings = @$_POST['gtbabel'];
                foreach (
                    [
                        'prefix_source_lng',
                        'translate_text_nodes',
                        'translate_default_tag_nodes',
                        'html_lang_attribute',
                        'html_hreflang_tags',
                        'debug_translations',
                        'auto_add_translations_to_gettext',
                        'auto_translation',
                        'api_stats'
                    ]
                    as $checkbox__value
                ) {
                    if (@$settings[$checkbox__value] == '1') {
                        $settings[$checkbox__value] = true;
                    } else {
                        $settings[$checkbox__value] = false;
                    }
                }
                foreach (
                    [
                        'exclude_urls',
                        'exclude_dom',
                        'force_tokenize',
                        'google_translation_api_key',
                        'microsoft_translation_api_key'
                    ]
                    as $exclude__value
                ) {
                    $post_data = $settings[$exclude__value];
                    $settings[$exclude__value] = [];
                    if ($post_data != '') {
                        foreach (explode(PHP_EOL, $post_data) as $post_data__value) {
                            $settings[$exclude__value][] = trim($post_data__value);
                        }
                    }
                }

                $post_data = $settings['prevent_publish_urls'];
                $settings['prevent_publish_urls'] = [];
                if ($post_data != '') {
                    foreach (explode(PHP_EOL, $post_data) as $post_data__value) {
                        $post_data__value_parts = explode(':', $post_data__value);
                        if (!empty($post_data__value_parts)) {
                            $settings['prevent_publish_urls'][$post_data__value_parts[0]] = explode(
                                ',',
                                __::trim_whitespace($post_data__value_parts[1])
                            );
                        }
                    }
                }

                $post_data = $settings['hide_languages'];
                if (array_key_exists('/*', $settings['prevent_publish_urls'])) {
                    unset($settings['prevent_publish_urls']['/*']);
                }
                if (!empty($post_data)) {
                    $settings['prevent_publish_urls']['/*'] = [];
                    foreach ($post_data as $post_data__key => $post_data__value) {
                        foreach (
                            $settings['prevent_publish_urls']
                            as $prevent_publish_urls__key => $prevent_publish_urls__value
                        ) {
                            if ($prevent_publish_urls__key !== '/*') {
                                continue;
                            }
                            if (!in_array($post_data__key, $prevent_publish_urls__value)) {
                                $settings['prevent_publish_urls'][$prevent_publish_urls__key][] = $post_data__key;
                            }
                        }
                    }
                }
                unset($settings['hide_languages']);

                $post_data = $settings['include_dom'];
                $settings['include_dom'] = [];
                if (!empty(@$post_data['selector'])) {
                    foreach ($post_data['selector'] as $post_data__key => $post_data__value) {
                        if (
                            @$post_data['selector'][$post_data__key] == '' &&
                                @$post_data['attribute'][$post_data__key] == '' &&
                            @$post_data['context'][$post_data__key] == ''
                        ) {
                            continue;
                        }
                        $settings['include_dom'][] = [
                            'selector' => $post_data['selector'][$post_data__key],
                            'attribute' => $post_data['attribute'][$post_data__key],
                            'context' => $post_data['context'][$post_data__key]
                        ];
                    }
                }

                $post_data = $settings['localize_js'];
                $settings['localize_js'] = [];
                if (!empty(@$post_data['string'])) {
                    foreach ($post_data['string'] as $post_data__key => $post_data__value) {
                        if (
                            @$post_data['string'][$post_data__key] == '' &&
                            @$post_data['context'][$post_data__key] == ''
                        ) {
                            continue;
                        }
                        $settings['localize_js'][] = [
                            $post_data['string'][$post_data__key],
                            $post_data['context'][$post_data__key] != '' ? $post_data['context'][$post_data__key] : null
                        ];
                    }
                }

                $settings['languages'] = array_keys($settings['languages']);
                update_option('gtbabel_settings', $settings);
                // refresh gtbabel with new options
                $this->start();
            }
            if (isset($_POST['reset_settings'])) {
                delete_option('gtbabel_settings');
                $this->setDefaultSettingsToOption();
                $this->start();
            }
            if (isset($_POST['reset_translations'])) {
                $this->reset();
            }
            $message =
                '<div class="gtbabel__notice notice notice-success is-dismissible"><p>' .
                __('Successfully edited', 'gtbabel-plugin') .
                '</p></div>';
        }

        $settings = get_option('gtbabel_settings');

        echo '<div class="gtbabel gtbabel--settings wrap">';
        echo '<form class="gtbabel__form" method="post" action="' . admin_url('admin.php?page=gtbabel-settings') . '">';
        echo '<h1 class="gtbabel__title">🦜 Gtbabel 🦜</h1>';
        echo $message;
        echo '<h2 class="gtbabel__subtitle">' . __('Settings', 'gtbabel-plugin') . '</h2>';
        echo '<ul class="gtbabel__fields">';
        echo '<li class="gtbabel__field">';
        echo '<label class="gtbabel__label">';
        echo __('Languages', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<ul class="gtbabel__languagelist">';
        foreach ($this->gtbabel->settings->getDefaultLanguages() as $languages__key => $languages__value) {
            echo '<li class="gtbabel__languagelist-item">';
            echo '<label class="gtbabel__languagelist-label">';
            echo '<input class="gtbabel__input gtbabel__input--checkbox" type="checkbox" name="gtbabel[languages][' .
                $languages__key .
                ']"' .
                (in_array($languages__key, $settings['languages']) == '1' ? ' checked="checked"' : '') .
                ' value="1" />';
            echo $languages__value;
            echo '</label>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_lng_source" class="gtbabel__label">';
        echo __('Source language', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<select class="gtbabel__input gtbabel__input--select" id="gtbabel_lng_source" name="gtbabel[lng_source]">';
        echo '<option value="">&ndash;&ndash;</option>';
        foreach ($this->gtbabel->settings->getDefaultLanguages() as $languages__key => $languages__value) {
            echo '<option value="' .
                $languages__key .
                '"' .
                ($settings['lng_source'] == $languages__key ? ' selected="selected"' : '') .
                '>' .
                $languages__value .
                '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_lng_folder" class="gtbabel__label">';
        echo __('Language folder', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<input class="gtbabel__input" type="text" id="gtbabel_lng_folder" name="gtbabel[lng_folder]" value="' .
            $settings['lng_folder'] .
            '" />';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_log_folder" class="gtbabel__label">';
        echo __('Log folder', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<input class="gtbabel__input" type="text" id="gtbabel_log_folder" name="gtbabel[log_folder]" value="' .
            $settings['log_folder'] .
            '" />';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_debug_translations" class="gtbabel__label">';
        echo __('Enable debug mode', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<input class="gtbabel__input gtbabel__input--checkbox" type="checkbox" id="gtbabel_debug_translations" name="gtbabel[debug_translations]" value="1"' .
            ($settings['debug_translations'] == '1' ? ' checked="checked"' : '') .
            ' />';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_hide_languages" class="gtbabel__label">';
        echo __('Hide languages', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<ul class="gtbabel__languagelist">';
        foreach (
            $this->gtbabel->settings->getSelectedLanguagesWithoutSource()
            as $languages__key => $languages__value
        ) {
            $checked = false;
            if (!empty($settings['prevent_publish_urls'])) {
                foreach (
                    $settings['prevent_publish_urls']
                    as $prevent_publish_urls__key => $prevent_publish_urls__value
                ) {
                    if (
                        $prevent_publish_urls__key === '/*' &&
                        in_array($languages__key, $prevent_publish_urls__value)
                    ) {
                        $checked = true;
                        break;
                    }
                }
            }
            echo '<li class="gtbabel__languagelist-item">';
            echo '<label class="gtbabel__languagelist-label">';
            echo '<input class="gtbabel__input gtbabel__input--checkbox" type="checkbox" name="gtbabel[hide_languages][' .
                $languages__key .
                ']"' .
                ($checked === true ? ' checked="checked"' : '') .
                ' value="1" />';
            echo $languages__value;
            echo '</label>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_prefix_source_lng" class="gtbabel__label">';
        echo __('Prefix source language urls', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<input class="gtbabel__input gtbabel__input--checkbox" type="checkbox" id="gtbabel_prefix_source_lng" name="gtbabel[prefix_source_lng]" value="1"' .
            ($settings['prefix_source_lng'] == '1' ? ' checked="checked"' : '') .
            ' />';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_redirect_root_domain" class="gtbabel__label">';
        echo __('Redirect root domain to', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<select class="gtbabel__input gtbabel__input--select" id="gtbabel_redirect_root_domain" name="gtbabel[redirect_root_domain]">';
        echo '<option value="browser"' .
            ($settings['redirect_root_domain'] == 'browser' ? ' selected="selected"' : '') .
            '>' .
            __('Browser language', 'gtbabel-plugin') .
            '</option>';
        echo '<option value="source"' .
            ($settings['redirect_root_domain'] == 'source' ? ' selected="selected"' : '') .
            '>' .
            __('Source language', 'gtbabel-plugin') .
            '</option>';
        echo '</select>';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_translate_text_nodes" class="gtbabel__label">';
        echo __('Translate text nodes', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<input class="gtbabel__input gtbabel__input--checkbox" type="checkbox" id="gtbabel_translate_text_nodes" name="gtbabel[translate_text_nodes]" value="1"' .
            ($settings['translate_text_nodes'] == '1' ? ' checked="checked"' : '') .
            ' />';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_translate_default_tag_nodes" class="gtbabel__label">';
        echo __('Translate additional nodes', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<input class="gtbabel__input gtbabel__input--checkbox" type="checkbox" id="gtbabel_translate_default_tag_nodes" name="gtbabel[translate_default_tag_nodes]" value="1"' .
            ($settings['translate_default_tag_nodes'] == '1' ? ' checked="checked"' : '') .
            ' />';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_html_lang_attribute" class="gtbabel__label">';
        echo __('Set html lang attribute', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<input class="gtbabel__input gtbabel__input--checkbox" type="checkbox" id="gtbabel_html_lang_attribute" name="gtbabel[html_lang_attribute]" value="1"' .
            ($settings['html_lang_attribute'] == '1' ? ' checked="checked"' : '') .
            ' />';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_html_hreflang_tags" class="gtbabel__label">';
        echo __('Add html hreflang tags', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<input class="gtbabel__input gtbabel__input--checkbox" type="checkbox" id="gtbabel_html_hreflang_tags" name="gtbabel[html_hreflang_tags]" value="1"' .
            ($settings['html_hreflang_tags'] == '1' ? ' checked="checked"' : '') .
            ' />';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_auto_add_translations_to_gettext" class="gtbabel__label">';
        echo __('Auto add translations to gettext', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<input class="gtbabel__input gtbabel__input--checkbox" type="checkbox" id="gtbabel_auto_add_translations_to_gettext" name="gtbabel[auto_add_translations_to_gettext]" value="1"' .
            ($settings['auto_add_translations_to_gettext'] == '1' ? ' checked="checked"' : '') .
            ' />';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_auto_translation" class="gtbabel__label">';
        echo __('Enable automatic translation', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<input class="gtbabel__input gtbabel__input--checkbox" type="checkbox" id="gtbabel_auto_translation" name="gtbabel[auto_translation]" value="1"' .
            ($settings['auto_translation'] == '1' ? ' checked="checked"' : '') .
            ' />';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_auto_translation_service" class="gtbabel__label">';
        echo __('Translation service', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<select class="gtbabel__input gtbabel__input--select" id="gtbabel_auto_translation_service" name="gtbabel[auto_translation_service]">';
        echo '<option value="">&ndash;&ndash;</option>';
        echo '<option value="google"' .
            ($settings['auto_translation_service'] == 'google' ? ' selected="selected"' : '') .
            '>Google</option>';
        echo '<option value="microsoft"' .
            ($settings['auto_translation_service'] == 'microsoft' ? ' selected="selected"' : '') .
            '>Microsoft</option>';
        echo '</select>';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_google_translation_api_key" class="gtbabel__label">';
        echo __('Google Translation API Key', 'gtbabel-plugin') .
            ' (<a href="https://console.cloud.google.com/apis/library/translate.googleapis.com" target="_blank">' .
            __('Link', 'gtbabel-plugin') .
            '</a>)';
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<textarea class="gtbabel__input gtbabel__input--textarea" id="gtbabel_google_translation_api_key" name="gtbabel[google_translation_api_key]">' .
            (is_array($settings['google_translation_api_key'])
                ? implode(PHP_EOL, $settings['google_translation_api_key'])
                : $settings['google_translation_api_key']) .
            '</textarea>';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_microsoft_translation_api_key" class="gtbabel__label">';
        echo __('Microsoft Translation API Key', 'gtbabel-plugin') .
            ' (<a href="https://azure.microsoft.com/de-de/services/cognitive-services/translator-text-api" target="_blank">' .
            __('Link', 'gtbabel-plugin') .
            '</a>)';
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<textarea class="gtbabel__input gtbabel__input--textarea" id="gtbabel_microsoft_translation_api_key" name="gtbabel[microsoft_translation_api_key]">' .
            (is_array($settings['microsoft_translation_api_key'])
                ? implode(PHP_EOL, $settings['microsoft_translation_api_key'])
                : $settings['microsoft_translation_api_key']) .
            '</textarea>';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_api_stats" class="gtbabel__label">';
        echo __('Enable translation api usage stats', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<input class="gtbabel__input gtbabel__input--checkbox" type="checkbox" id="gtbabel_api_stats" name="gtbabel[api_stats]" value="1"' .
            ($settings['api_stats'] == '1' ? ' checked="checked"' : '') .
            ' />';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_prevent_publish_urls" class="gtbabel__label">';
        echo __('Prevent publish of pages', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<textarea class="gtbabel__input gtbabel__input--textarea" id="gtbabel_prevent_publish_urls" name="gtbabel[prevent_publish_urls]">';
        if (!empty($settings['prevent_publish_urls'])) {
            echo implode(
                PHP_EOL,
                array_map(
                    function ($prevent_publish_urls__value, $prevent_publish_urls__key) {
                        return $prevent_publish_urls__key . ':' . implode(',', $prevent_publish_urls__value);
                    },
                    $settings['prevent_publish_urls'],
                    array_keys($settings['prevent_publish_urls'])
                )
            );
        }
        echo '</textarea>';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_exclude_urls" class="gtbabel__label">';
        echo __('Exclude urls', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<textarea class="gtbabel__input gtbabel__input--textarea" id="gtbabel_exclude_urls" name="gtbabel[exclude_urls]">' .
            implode(PHP_EOL, $settings['exclude_urls']) .
            '</textarea>';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_exclude_dom" class="gtbabel__label">';
        echo __('Exclude dom nodes', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<textarea class="gtbabel__input gtbabel__input--textarea" id="gtbabel_exclude_dom" name="gtbabel[exclude_dom]">' .
            implode(PHP_EOL, $settings['exclude_dom']) .
            '</textarea>';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_force_tokenize" class="gtbabel__label">';
        echo __('Force tokenize', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<textarea class="gtbabel__input gtbabel__input--textarea" id="gtbabel_force_tokenize" name="gtbabel[force_tokenize]">' .
            implode(PHP_EOL, $settings['force_tokenize']) .
            '</textarea>';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label class="gtbabel__label">';
        echo __('Include dom nodes', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<div class="gtbabel__repeater">';
        echo '<ul class="gtbabel__repeater-list">';
        if (empty(@$settings['include_dom'])) {
            $settings['include_dom'] = [['selector' => '', 'attribute' => '', 'context' => '']];
        }
        foreach ($settings['include_dom'] as $include_dom__value) {
            echo '<li class="gtbabel__repeater-listitem gtbabel__repeater-listitem--count-3">';
            echo '<input class="gtbabel__input" type="text" name="gtbabel[include_dom][selector][]" value="' .
                $include_dom__value['selector'] .
                '" placeholder="selector" />';
            echo '<input class="gtbabel__input" type="text" name="gtbabel[include_dom][attribute][]" value="' .
                $include_dom__value['attribute'] .
                '" placeholder="attribute" />';
            echo '<input class="gtbabel__input" type="text" name="gtbabel[include_dom][context][]" value="' .
                $include_dom__value['context'] .
                '" placeholder="context" />';
            echo '<a href="#" class="gtbabel__repeater-remove button button-secondary">' .
                __('Remove', 'gtbabel-plugin') .
                '</a>';
            echo '</li>';
        }
        echo '</ul>';
        echo '<a href="#" class="gtbabel__repeater-add button button-secondary">' .
            __('Add', 'gtbabel-plugin') .
            '</a>';
        echo '</div>';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label class="gtbabel__label">';
        echo __('Provide strings in JavaScript', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<div class="gtbabel__repeater">';
        echo '<ul class="gtbabel__repeater-list">';
        if (empty(@$settings['localize_js'])) {
            $settings['localize_js'] = [['', '']];
        }
        foreach ($settings['localize_js'] as $localize_js__value) {
            echo '<li class="gtbabel__repeater-listitem gtbabel__repeater-listitem--count-2">';
            echo '<input class="gtbabel__input" type="text" name="gtbabel[localize_js][string][]" value="' .
                $localize_js__value[0] .
                '" placeholder="string" />';
            echo '<input class="gtbabel__input" type="text" name="gtbabel[localize_js][context][]" value="' .
                $localize_js__value[1] .
                '" placeholder="context" />';
            echo '<a href="#" class="gtbabel__repeater-remove button button-secondary">' .
                __('Remove', 'gtbabel-plugin') .
                '</a>';
            echo '</li>';
        }
        echo '</ul>';
        echo '<a href="#" class="gtbabel__repeater-add button button-secondary">' .
            __('Add', 'gtbabel-plugin') .
            '</a>';
        echo '</div>';
        echo '</div>';
        echo '</li>';

        echo '</ul>';

        echo '<input class="gtbabel__submit button button-primary" name="save_settings" value="' .
            __('Save', 'gtbabel-plugin') .
            '" type="submit" />';

        echo '<h2 class="gtbabel__subtitle">' . __('Translate complete website', 'gtbabel-plugin') . '</h2>';
        echo '<p class="gtbabel__paragraph">' . __('Only new strings are translated.', 'gtbabel-plugin') . '</p>';
        echo '<ul class="gtbabel__fields">';
        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_delete_unused" class="gtbabel__label">';
        echo __('Delete unused translations', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<input class="gtbabel__input gtbabel__input--checkbox" id="gtbabel_delete_unused" type="checkbox" value="1" />';
        echo '</div>';
        echo '</li>';
        echo '</ul>';

        echo '<a data-loading-text="' .
            __('Loading', 'gtbabel-plugin') .
            '..." data-href="' .
            admin_url('admin.php?page=gtbabel-settings&gtbabel_auto_translate=1') .
            '" href="#" class="gtbabel__submit gtbabel__submit--auto-translate button button-secondary">' .
            __('Translate', 'gtbabel-plugin') .
            '</a>';
        if (@$_GET['gtbabel_auto_translate'] == '1') {
            $chunk = 0;
            if (@$_GET['gtbabel_auto_translate_chunk'] != '') {
                $chunk = intval($_GET['gtbabel_auto_translate_chunk']);
            }
            $delete_unused = false;
            if (@$_GET['gtbabel_delete_unused'] == '1') {
                $delete_unused = true;
            }
            $delete_unused_since_date = null;
            if (__::x(@$_GET['gtbabel_delete_unused_since_date'])) {
                $delete_unused_since_date = $_GET['gtbabel_delete_unused_since_date'];
            }
            $this->initBackendAutoTranslate($chunk, $delete_unused, $delete_unused_since_date);
        }

        if ($settings['api_stats'] == '1') {
            echo '<div class="gtbabel__api-stats">';
            echo '<h2 class="gtbabel__subtitle">' . __('Translation api usage stats', 'gtbabel-plugin') . '</h2>';
            echo '<ul>';
            foreach (
                ['google' => 'Google Translation API', 'microsoft' => 'Microsoft Translation API']
                as $service__key => $service__value
            ) {
                echo '<li>';
                echo $service__value . ': ';
                $cur = $this->gtbabel->log->apiStatsGet($service__key);
                echo $cur;
                echo ' ';
                echo __('Characters', 'gtbabel-plugin');
                $costs = 0;
                if ($service__key === 'google') {
                    $costs = $cur * (20 / 1000000) * 0.92;
                }
                if ($service__value === 'microsoft') {
                    $costs = $cur * (8.433 / 1000000);
                }
                echo ' (~' . number_format(round($costs, 2), 2, ',', '.') . ' €)';
                echo '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        echo '<h2 class="gtbabel__subtitle">' . __('Reset settings', 'gtbabel-plugin') . '</h2>';
        echo '<input data-question="' .
            __('Please enter REMOVE to confirm!', 'gtbabel-plugin') .
            '" class="gtbabel__submit gtbabel__submit--reset button button-secondary" name="reset_settings" value="' .
            __('Reset', 'gtbabel-plugin') .
            '" type="submit" />';

        echo '<h2 class="gtbabel__subtitle">' . __('Reset translations', 'gtbabel-plugin') . '</h2>';
        echo '<input data-question="' .
            __('Please enter REMOVE to confirm!', 'gtbabel-plugin') .
            '" class="gtbabel__submit gtbabel__submit--reset button button-secondary" name="reset_translations" value="' .
            __('Reset', 'gtbabel-plugin') .
            '" type="submit" />';

        echo '</form>';
        echo '</div>';
    }

    private function initBackendStringTranslation()
    {
        $message = '';

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            if (isset($_POST['save_translations'])) {
                if (!empty(@$_POST['gtbabel'])) {
                    foreach ($_POST['gtbabel'] as $post__key => $post__value) {
                        if (!empty(@$post__value['translations'])) {
                            foreach ($post__value['translations'] as $translations__key => $translations__value) {
                                $this->gtbabel->gettext->editTranslationFromFiles(
                                    $post__key,
                                    $translations__value,
                                    $translations__key
                                );
                            }
                        }
                        if (@$post__value['delete'] == '1') {
                            $this->gtbabel->gettext->deleteTranslationFromFiles($post__key);
                        }
                        if (array_key_exists('shared', $post__value)) {
                            $this->gtbabel->gettext->editSharedValueFromFiles($post__key, $post__value['shared']);
                        }
                    }
                }
            }
            if (isset($_POST['save_publish'])) {
                if (!empty(@$_POST['gtbabel'])) {
                    foreach ($_POST['gtbabel'] as $post__key => $post__value) {
                        $url = $post__key;
                        $lngs = [];
                        foreach ($post__value as $post__value__key => $post__value__value) {
                            if ($post__value__value == '1') {
                                $lngs[] = $post__value__key;
                            }
                        }
                        $this->gtbabel->publish->edit($url, $lngs);
                    }
                    $this->changeSetting('prevent_publish_urls', $this->gtbabel->settings->get('prevent_publish_urls'));
                }
            }
            $message =
                '<div class="gtbabel__notice notice notice-success is-dismissible"><p>' .
                __('Successfully edited', 'gtbabel-plugin') .
                '</p></div>';
        }

        $translations = $this->initBackendTranslations();

        $pagination = $this->initBackendPagination($translations);

        if ($pagination->count > 0) {
            $translations = array_slice(
                $translations,
                ($pagination->cur - 1) * $pagination->per_page,
                $pagination->per_page
            );
        }

        echo '<div class="gtbabel gtbabel--trans wrap">';
        echo '<h1 class="gtbabel__title">🦜 Gtbabel 🦜</h1>';
        echo $message;
        echo '<h2 class="gtbabel__subtitle">' . __('Search translations', 'gtbabel-plugin') . '</h2>';

        echo '<p class="gtbabel__paragraph">' .
            __('Translations available', 'gtbabel-plugin') .
            ': ' .
            $pagination->count .
            '</p>';

        echo '<div class="gtbabel__search">';
        echo '<form class="gtbabel__form" method="get" action="' . admin_url('admin.php') . '">';
        echo '<input type="hidden" name="page" value="gtbabel-trans" />';
        echo '<input type="hidden" name="p" value="1" />';
        echo '<input class="gtbabel__input" type="text" name="url" value="' .
            @$_GET['url'] . '" placeholder="' . __('URL in source language', 'gtbabel-plugin') .
            '" />';
        echo '<input class="gtbabel__input" type="text" name="s" value="' .
            @$_GET['s'] . '" placeholder="' . __('Search term', 'gtbabel-plugin') .
            '" />';
        echo '<input class="gtbabel__submit button button-secondary" value="' .
            __('Search', 'gtbabel-plugin') .
            '" type="submit" />';
        echo '</form>';
        echo '</div>';

        if (@$_GET['url'] != '') {
            echo '<h2 class="gtbabel__subtitle">' . __('Publish status', 'gtbabel-plugin') . '</h2>';
            echo '<div class="gtbabel__publish">';
            echo '<form class="gtbabel__form" method="post" action="' .
                admin_url(
                    'admin.php?page=gtbabel-trans' .
                        ($pagination->cur != '' ? '&p=' . $pagination->cur : '') .
                        (isset($_GET['s']) != '' ? '&s=' . $_GET['s'] : '') .
                        (isset($_GET['url']) != '' ? '&url=' . $_GET['url'] : '')
                ) .
                '">';
            echo '<p class="gtbabel__paragraph">';
            echo __('Status of source url', 'gtbabel-plugin') . ': ';
            $url_published = $this->isUrlPublished($_GET['url']);
            if ($url_published === true) {
                echo __('Published', 'gtbabel-plugin');
            } else {
                echo __('Unpublished', 'gtbabel-plugin');
            }
            echo '</p>';
            echo '<ul class="gtbabel__publish-list">';
            foreach ($this->gtbabel->settings->getSelectedLanguages() as $lng__key => $lng__value) {
                echo '<li class="gtbabel__publish-listitem">';
                $disabled = $this->gtbabel->settings->getSourceLanguageCode() === $lng__key || $url_published === false;
                $checked =
                    ($this->gtbabel->settings->getSourceLanguageCode() === $lng__key && $url_published === true) ||
                    ($this->gtbabel->settings->getSourceLanguageCode() !== $lng__key &&
                        !$this->gtbabel->publish->isPrevented($_GET['url'], $lng__key, false));
                echo '<input' .
                    ($disabled ? ' disabled="disabled"' : '') .
                    ($checked ? ' checked="checked"' : '') .
                    ' class="gtbabel__input gtbabel__input--checkbox gtbabel__input--inverse" type="checkbox" id="gtbabel_publish_' .
                    $lng__key .
                    '" />';
                if ($this->gtbabel->settings->getSourceLanguageCode() !== $lng__key) {
                    echo '<input type="hidden" name="gtbabel[' .
                        $_GET['url'] .
                        '][' .
                        $lng__key .
                        ']" value="' .
                        ($checked ? '0' : '1') .
                        '" />';
                }
                echo '<label for="gtbabel_publish_' . $lng__key . '" class="gtbabel__label">';
                echo $lng__value;
                echo '</label>';
                $link = $_GET['url'];
                if ($this->gtbabel->settings->getSourceLanguageCode() !== $lng__key) {
                    $link = $this->gtbabel->gettext->getUrlTranslationInLanguage($lng__key, $_GET['url']);
                }
                echo '<a class="gtbabel__publish-listitem-link" href="' . $link . '" target="_blank">' . $link . '</a>';
                echo '</li>';
            }
            echo '</ul>';
            if ($url_published === true) {
                echo '<input class="gtbabel__submit button button-secondary" name="save_publish" value="' .
                    __('Save', 'gtbabel-plugin') .
                    '" type="submit" />';
            }
            echo '</form>';
            echo '</div>';
        }

        if (!empty($translations)) {
            echo '<h2 class="gtbabel__subtitle">' . __('String translations', 'gtbabel-plugin') . '</h2>';

            if (@$_GET['url'] != '') {
                echo '<p class="gtbabel__paragraph gtbabel__highlight">' .
                    __('Warning: Some strings below could also be used on other pages.', 'gtbabel-plugin') .
                    '</p>';
            }

            echo '<form class="gtbabel__form" method="post" action="' .
                admin_url(
                    'admin.php?page=gtbabel-trans' .
                        ($pagination->cur != '' ? '&p=' . $pagination->cur : '') .
                        (isset($_GET['s']) != '' ? '&s=' . $_GET['s'] : '') .
                        (isset($_GET['url']) != '' ? '&url=' . $_GET['url'] : '')
                ) .
                '">';

            echo '<table class="gtbabel__table">';
            echo '<thead class="gtbabel__table-head">';
            echo '<tr class="gtbabel__table-row">';
            echo '<td class="gtbabel__table-cell">' . $this->gtbabel->settings->getSourceLanguageLabel() . '</td>';
            foreach ($this->gtbabel->settings->getSelectedLanguagesWithoutSource() as $languages__value) {
                echo '<td class="gtbabel__table-cell">' . $languages__value . '</td>';
            }
            echo '<td class="gtbabel__table-cell">' . __('Context', 'gtbabel-plugin') . '</td>';
            echo '<td class="gtbabel__table-cell">' . __('Shared', 'gtbabel-plugin') . '</td>';
            echo '<td class="gtbabel__table-cell">' . __('Delete', 'gtbabel-plugin') . '</td>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody class="gtbabel__table-body">';
            foreach ($translations as $translations__key => $translations__value) {
                echo '<tr class="gtbabel__table-row">';
                echo '<td class="gtbabel__table-cell">';
                echo '<textarea class="gtbabel__input gtbabel__input--textarea" name="gtbabel[' .
                    $translations__key .
                    '][orig]" disabled="disabled">' .
                    $translations__value['orig'] .
                    '</textarea>';
                echo '</td>';
                foreach (
                    $this->gtbabel->settings->getSelectedLanguagesWithoutSource()
                    as $languages__key => $languages__value
                ) {
                    echo '<td class="gtbabel__table-cell">';
                    echo '<textarea class="gtbabel__input gtbabel__input--textarea gtbabel__input--on-change" data-name="gtbabel[' .
                        $translations__key .
                        '][translations][' .
                        $languages__key .
                        ']">' .
                        @$translations__value['translations'][$languages__key] .
                        '</textarea>';
                    echo '</td>';
                }
                echo '<td class="gtbabel__table-cell">';
                echo '<textarea class="gtbabel__input gtbabel__input--textarea" name="gtbabel[' .
                    $translations__key .
                    '][context]" disabled="disabled">' .
                    $translations__value['context'] .
                    '</textarea>';
                echo '</td>';
                echo '<td class="gtbabel__table-cell">';
                echo '<input class="gtbabel__input gtbabel__input--checkbox gtbabel__input--on-change gtbabel__input--submit-unchecked" type="checkbox" data-name="gtbabel[' .
                    $translations__key .
                    '][shared]" value="1"' .
                    ($translations__value['shared'] == '1' ? ' checked="checked"' : '') .
                    ' />';
                echo '</td>';
                echo '<td class="gtbabel__table-cell">';
                echo '<input class="gtbabel__input gtbabel__input--checkbox" type="checkbox" name="gtbabel[' .
                    $translations__key .
                    '][delete]" value="1" />';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody>';
            echo '</table>';
            echo $pagination->html;

            echo '<input class="gtbabel__submit button button-primary" name="save_translations" value="' .
                __('Save', 'gtbabel-plugin') .
                '" type="submit" />';

            echo '</form>';
        } else {
            echo '<p>' . __('No translations available.', 'gtbabel-plugin') . '</p>';
        }

        echo '</div>';
    }

    private function initBackendTranslationServices()
    {
        $message = '';

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            if (isset($_POST['upload_file'])) {
                if (@$_POST['gtbabel']['language'] != '' && @$_FILES['gtbabel']['name']['file'] != '') {
                    $extension = strtolower(end(explode('.', $_FILES['gtbabel']['name']['file'])));
                    $allowed_extension = [
                        'po' => ['application/octet-stream']
                    ];
                    if (
                        array_key_exists($extension, $allowed_extension) &&
                        in_array($_FILES['gtbabel']['type']['file'], $allowed_extension[$extension]) &&
                        $_FILES['gtbabel']['size']['file'] < 4000 * 1024 &&
                        $_FILES['gtbabel']['error']['file'] == 0
                    ) {
                        move_uploaded_file(
                            $_FILES['gtbabel']['tmp_name']['file'],
                            $this->gtbabel->gettext->getLngFilename('po', $_POST['gtbabel']['language'])
                        );
                        $this->gtbabel->gettext->convertPoToMo(
                            $this->gtbabel->gettext->getLngFilename('po', $_POST['gtbabel']['language'])
                        );
                        $message =
                            '<div class="gtbabel__notice notice notice-success is-dismissible"><p>' .
                            __('Successfully uploaded', 'gtbabel-plugin') .
                            '</p></div>';
                    } else {
                        $message =
                            '<div class="gtbabel__notice notice notice-error is-dismissible"><p>' .
                            __('An error occured', 'gtbabel-plugin') .
                            '</p></div>';
                    }
                }
            }
        }

        echo '<div class="gtbabel gtbabel--services wrap">';
        echo '<h1 class="gtbabel__title">🦜 Gtbabel 🦜</h1>';
        echo $message;
        echo '<h2 class="gtbabel__subtitle">' . __('Translation services', 'gtbabel-plugin') . '</h2>';

        echo '<ol class="gtbabel__steps">';
        echo '<li class="gtbabel__step">';
        echo sprintf(
            __('Register and login at %sICanLocalize%s.', 'gtbabel-plugin'),
            '<a href="https://www.icanlocalize.com" target="_blank">',
            '</a>'
        );
        echo '</li>';
        echo '<li class="gtbabel__step">';
        echo sprintf(
            __(
                'Create a new %sSoftware localization project%s and pick the same original / target languages as in Gtbabel.',
                'gtbabel-plugin'
            ),
            '<a href="https://www.icanlocalize.com/text_resources/new" target="_blank">',
            '</a>'
        );
        echo '</li>';
        echo '<li class="gtbabel__step">';
        echo sprintf(
            __('Upload your current .pot-Template file, which can be downloaded %shere%s.', 'gtbabel-plugin'),
            '<a download="template_' .
                date('Y-m-d_H-i-s') .
                '.pot" href="' .
                $this->gtbabel->gettext->getLngFilenamePublic('pot', '_template') .
                '" target="_blank">',
            '</a>'
        );
        echo '</li>';
        echo '<li class="gtbabel__step">';
        echo __('Add all strings for translation.', 'gtbabel-plugin');
        echo '</li>';
        echo '<li class="gtbabel__step">';
        echo __('Review and place the translation order.', 'gtbabel-plugin');
        echo '</li>';
        echo '<li class="gtbabel__step">';
        echo __('Wait for the job to finish and get back individual .po-files.', 'gtbabel-plugin');
        echo '</li>';
        echo '<li class="gtbabel__step">';
        echo __('Reupload the .po-files via the following upload-form.', 'gtbabel-plugin');
        echo '</li>';
        echo '</ol>';

        echo '<h2 class="gtbabel__subtitle">' . __('File upload', 'gtbabel-plugin') . '</h2>';

        echo '<form enctype="multipart/form-data" class="gtbabel__form" method="post" action="' .
            admin_url('admin.php?page=gtbabel-services') .
            '">';

        echo '<ul class="gtbabel__fields">';
        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_language" class="gtbabel__label">';
        echo __('Language', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<select required="required" class="gtbabel__input gtbabel__input--select" id="gtbabel_language" name="gtbabel[language]">';
        echo '<option value="">&ndash;&ndash;</option>';
        foreach (
            $this->gtbabel->settings->getSelectedLanguagesWithoutSource()
            as $languages__key => $languages__value
        ) {
            echo '<option value="' . $languages__key . '">' . $languages__value . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '</li>';

        echo '<li class="gtbabel__field">';
        echo '<label for="gtbabel_file" class="gtbabel__label">';
        echo __('.po-File', 'gtbabel-plugin');
        echo '</label>';
        echo '<div class="gtbabel__inputbox">';
        echo '<input required="required" class="gtbabel__input gtbabel__input--file" type="file" name="gtbabel[file]" id="gtbabel_file" accept=".po" />';
        echo '</div>';
        echo '</li>';
        echo '</ul>';

        echo '<input class="gtbabel__submit button button-primary" name="upload_file" value="' .
            __('Upload', 'gtbabel-plugin') .
            '" type="submit" />';
        echo '</form>';
        echo '</div>';
    }

    private function initBackendTranslations()
    {
        $translations = [];

        if (@$_GET['url'] != '') {
            $this->changeSetting('discovery_log', true);
            $url = $_GET['url'];
            $urls = [];
            $urls[] = $this->getNoCacheUrl($url);
            $since_date = date('Y-m-d H:i:s');
            $this->fetch($this->getNoCacheUrl($url));
            // subsequent urls are now available (we need to refresh the current session)
            $this->start();
            foreach ($this->gtbabel->settings->getSelectedLanguageCodesWithoutSource() as $lngs__value) {
                $url_trans = $this->gtbabel->gettext->getUrlTranslationInLanguage($lngs__value, $url);
                $this->fetch($this->getNoCacheUrl($url_trans));
                $urls[] = $this->getNoCacheUrl($url_trans);
            }
            // restart again
            $this->start();
            $discovery_strings = $this->gtbabel->log->discoveryLogGet($since_date, $urls);
            $discovery_strings_map = [];
            foreach ($discovery_strings as $discovery_strings__key => $discovery_strings__value) {
                $discovery_strings_map[
                    md5($discovery_strings__value['string'] . '#' . ($discovery_strings__value['context'] ?? ''))
                ] = $discovery_strings__key;
            }
            // now auto set shared values
            // we now do a cool trick here: we fetch the homepage first to feed discovery url
            $home_url = get_home_url();
            $this->fetch($this->getNoCacheUrl($home_url));
            $this->start();
            foreach ($this->gtbabel->settings->getSelectedLanguageCodesWithoutSource() as $lngs__value) {
                $this->fetch(
                    $this->getNoCacheUrl($this->gtbabel->gettext->getUrlTranslationInLanguage($lngs__value, $home_url))
                );
            }
            $this->start();
            $this->gtbabel->gettext->autoEditSharedValueFromFiles($discovery_strings, $urls);
            $this->changeSetting('discovery_log', false);
        }

        $translations = $this->gtbabel->gettext->getAllTranslationsFromFiles();

        if (@$_GET['url'] != '') {
            // filter
            foreach ($translations as $translations__key => $translations__value) {
                if (!array_key_exists($translations__key, $discovery_strings_map)) {
                    unset($translations[$translations__key]);
                }
            }
            // sort
            foreach ($translations as $translations__key => $translations__value) {
                $translations[$translations__key]['order'] = $discovery_strings_map[$translations__key];
            }
            uasort($translations, function ($a, $b) {
                if ($a['shared'] === true) {
                    $a['shared'] = $a['shared'] === true ? 1 : 0;
                }
                if ($b['shared'] === true) {
                    $b['shared'] = $b['shared'] === true ? 1 : 0;
                }
                if ($a['shared'] !== $b['shared']) {
                    return $a['shared'] < $b['shared'] ? -1 : 1;
                }
                if ($a['context'] != $b['context']) {
                    return strcmp($a['context'], $b['context']);
                }
                return $a['order'] < $b['order'] ? -1 : 1;
            });
        }

        if (@$_GET['s'] != '') {
            // filter
            foreach ($translations as $translations__key => $translations__value) {
                if (mb_stripos($translations__value['orig'], @$_GET['s']) === false) {
                    unset($translations[$translations__key]);
                }
            }
        }

        return $translations;
    }

    private function initBackendPagination($translations)
    {
        $pagination = (object) [];
        $pagination->per_page = 50;
        $pagination->count = count($translations);
        $pagination->cur = @$_GET['p'] != '' ? intval($_GET['p']) : 1;
        $pagination->max = ceil($pagination->count / $pagination->per_page);
        $pagination->html = '';
        if ($pagination->max > 1) {
            $pagination->html .= '<ul class="gtbabel__pagination">';
            for ($p = 1; $p <= $pagination->max; $p++) {
                $pagination->html .= '<li class="gtbabel__pagination-item">';
                if ($pagination->cur !== $p) {
                    $pagination->html .=
                        '<a class="gtbabel__pagination-link" href="' .
                        admin_url('admin.php?page=gtbabel-trans&p=' . $p) .
                        '">' .
                        $p .
                        '</a>';
                } else {
                    $pagination->html .= '<span class="gtbabel__pagination-cur">' . $p . '</span>';
                }
                $pagination->html .= '</li>';
            }
            $pagination->html .= '</ul>';
        }
        return $pagination;
    }

    private function changeSetting($key, $value)
    {
        $settings = get_option('gtbabel_settings');
        $settings[$key] = $value;
        update_option('gtbabel_settings', $settings);
    }

    private function getSetting($key)
    {
        $settings = get_option('gtbabel_settings');
        if (!array_key_exists($key, $settings)) {
            return null;
        }
        return $settings[$key];
    }

    private function isUrlPublished($url)
    {
        /*
        - first we try to get the status via url_to_postid()
        - if that doesn't succeed, we try to get the status via fetch
        */
        $published = true;
        $post = url_to_postid($url);
        if ($post == 0) {
            $response = $this->fetch($url, false);
            if ($response->status == 404) {
                $published = false;
            }
        } else {
            if (get_post_status($post) !== 'publish') {
                $published = false;
            }
        }
        return $published;
    }

    private function getNoCacheUrl($url)
    {
        $url .= mb_strpos($url, '?') === false ? '?' : '&';
        $url .= 'no_cache=1';
        return $url;
    }

    private function fetch($url, $with_current_session = true)
    {
        return __::curl(
            $url,
            null,
            'GET',
            null,
            false,
            false,
            60,
            null,
            $with_current_session === true ? $_COOKIE : null
        );
    }

    private function initBackendAutoTranslate($chunk = 0, $delete_unused = false, $delete_unused_since_date = null)
    {
        $chunk_size = 5;

        echo '<div class="gtbabel__auto-translate">';

        // build general queue
        $queue = [];
        $urls = [];
        $query = new \WP_Query(['post_type' => 'any', 'posts_per_page' => '-1', 'post_status' => 'publish']);
        while ($query->have_posts()) {
            $query->the_post();
            $url = get_permalink();
            $urls[] = $url;
        }
        $query = new \WP_Term_Query(['hide_empty' => false]);
        if (!empty($query->terms)) {
            foreach ($query->terms as $terms__value) {
                $url = get_term_link($terms__value);
                // exclude non-public
                if (strpos($url, '?') !== false) {
                    continue;
                }
                $urls[] = $url;
            }
        }
        foreach ($urls as $urls__value) {
            $queue[] = ['url' => $urls__value, 'convert_to_lng' => null, 'refresh_after' => true];
            foreach ($this->gtbabel->settings->getSelectedLanguageCodesWithoutSource() as $lngs__value) {
                $queue[] = ['url' => $urls__value, 'convert_to_lng' => $lngs__value, 'refresh_after' => false];
            }
        }

        // prepare delete unused
        if ($delete_unused === true) {
            if ($delete_unused_since_date === null) {
                $delete_unused_since_date = date('Y-m-d H:i:s');
            }
            $this->changeSetting('discovery_log', true);
        }

        // do next chunk
        for ($i = $chunk_size * $chunk; $i < $chunk_size * $chunk + $chunk_size; $i++) {
            if (!isset($queue[$i])) {
                break;
            }
            // this is important, that we fetch the url in the source language first (this calls addCurrentUrlToTranslations())
            // if we call the source url, the translated urls are generated
            // important: the main fetch happened in a different session (the current session does not know of the translated slugs yet)
            // therefore we refresh gtbabel after every main url
            $url = $queue[$i]['url'];
            if ($queue[$i]['convert_to_lng'] !== null) {
                $url = $this->gtbabel->gettext->getUrlTranslationInLanguage($queue[$i]['convert_to_lng'], $url);
            }

            // append a pseudo get parameter, so that frontend cache plugins don't work
            $this->fetch($this->getNoCacheUrl($url));

            echo __('Loading', 'gtbabel-plugin');
            echo '... ' . $url . '<br/>';

            if ($queue[$i]['refresh_after'] === true) {
                $this->start();
            }
        }

        // progress
        $progress = ($chunk_size * $chunk + $chunk_size) / count($queue);
        if ($progress > 1) {
            $progress = 1;
        }
        $progress *= 100;
        $progress = round($progress, 2);
        $progress = number_format($progress, 2, ',', '');
        echo '<strong>';
        echo __('Progress', 'gtbabel-plugin');
        echo ': ' . $progress . '%';
        echo '</strong>';
        echo '<br/>';

        if ($delete_unused === true) {
            $this->changeSetting('discovery_log', false);
        }

        // if finished
        if ($chunk_size * $chunk + $chunk_size > count($queue) - 1) {
            if ($delete_unused === true) {
                $deleted = $this->gtbabel->gettext->deleteUnusedTranslations($delete_unused_since_date);
                echo __('Deleted strings', 'gtbabel-plugin') . ': ' . $deleted;
                echo '<br/>';
            }

            echo __('Finished', 'gtbabel-plugin');
        }

        // next
        else {
            $redirect_url = admin_url(
                'admin.php?page=gtbabel-settings&gtbabel_auto_translate=1&gtbabel_auto_translate_chunk=' .
                    ($chunk + 1) .
                    ($delete_unused === true ? '&gtbabel_delete_unused=1' : '') .
                    (__::x($delete_unused_since_date)
                        ? '&gtbabel_delete_unused_since_date=' . $delete_unused_since_date
                        : '')
            );
            echo '<a href="' . $redirect_url . '" class="gtbabel__auto-translate-next"></a>';
        }

        echo '</div>';
    }
}

$gtbabel = new Gtbabel();
new GtbabelWordPress($gtbabel);
