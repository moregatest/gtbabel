<?php
namespace vielhuber\gtbabel;

class Router
{
    public $utils;
    public $gettext;
    public $host;
    public $settings;

    function __construct(
        Utils $utils = null,
        Gettext $gettext = null,
        Host $host = null,
        Settings $settings = null
    ) {
        $this->utils = $utils ?: new Utils();
        $this->gettext = $gettext ?: new Gettext();
        $this->host = $host ?: new Host();
        $this->settings = $settings ?: new Settings();
    }

    function redirectPrefixedSourceLng()
    {
        if (!$this->gettext->sourceLngIsCurrentLng()) {
            return;
        }
        if (
            $this->settings->get('prefix_source_lng') === false &&
            $this->gettext->getCurrentPrefix() !== $this->gettext->getSourceLng()
        ) {
            return;
        }
        if (
            $this->settings->get('prefix_source_lng') === true &&
            $this->gettext->getCurrentPrefix() !== null
        ) {
            return;
        }
        if ($this->settings->get('prefix_source_lng') === false) {
            $url =
                trim($this->host->getCurrentHost(), '/') .
                '/' .
                trim(
                    str_replace(
                        $this->gettext->getSourceLng() . '/',
                        '',
                        $this->host->getCurrentPathWithArgs()
                    ),
                    '/'
                );
        } else {
            $url = '';
            $url .= trim($this->host->getCurrentHost(), '/');
            $url .= '/';
            $url .= $this->gettext->getCurrentLng();
            $url .= '/';
            if (trim($this->host->getCurrentPath(), '/') != '') {
                $url .= trim($this->host->getCurrentPath(), '/') . '/';
            }
        }
        header('Location: ' . $url, true, 301);
        die();
    }

    function initMagicRouter()
    {
        if ($this->gettext->sourceLngIsCurrentLng()) {
            if ($this->settings->get('prefix_source_lng') === false) {
                return;
            }
            if (
                strpos(
                    $this->host->getCurrentPathWithArgs(),
                    '/' . $this->gettext->getSourceLng()
                ) === 0
            ) {
                $path = substr(
                    $this->host->getCurrentPathWithArgs(),
                    mb_strlen('/' . $this->gettext->getSourceLng())
                );
            }
        } else {
            $path = $this->gettext->getCurrentPathTranslationsInLanguage(
                $this->gettext->getSourceLng(),
                true
            );
            $path = trim($path, '/');
            $path = '/' . $path . ($path != '' ? '/' : '') . $this->host->getCurrentArgs();
        }
        $_SERVER['REQUEST_URI'] = $path;
    }
}