<?php

declare(strict_types=1);

namespace App\Utils;

use App\Entity\Entry;
use App\Service\ImageManager;
use App\Service\SettingsManager;
use Embed\Embed as BaseEmbed;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class Embed
{
    public ?string $url = null;
    public ?string $title = null;
    public ?string $description = null;
    public ?string $image = null;
    public ?string $html = null;

    public function __construct(
        private CacheInterface $cache,
        private SettingsManager $settings,
        private LoggerInterface $logger,
    ) {
    }

    public function __clone(): void
    {
        unset($this->cache);
        unset($this->settings);
        unset($this->logger);
    }

    public function fetch($url): self
    {
        if ($this->settings->isLocalUrl($url)) {
            return $this;
        }

        $this->logger->debug('Embed:fetch: leftover data', [
            'url' => $this->url,
            'title' => $this->title,
            'description' => $this->description,
            'image' => $this->image,
            'html' => $this->html,
        ]);

        return $this->cache->get(
            'embed_'.md5($url),
            function (ItemInterface $item) use ($url) {
                $item->expiresAfter(3600);

                try {
                    $embed = (new BaseEmbed())->get($url);
                    $oembed = $embed->getOEmbed();
                } catch (\Exception $e) {
                    $this->logger->info('Embed:fetch: fetch failed: '.$e->getMessage());
                    $c = clone $this;

                    return $c;
                }

                $c = clone $this;

                $c->url = $url;
                $c->title = $embed->title;
                $c->description = $embed->description;
                $c->image = (string) $embed->image;
                $c->html = $this->cleanIframe($oembed->html('html'));

                try {
                    if (!$c->html && $embed->code) {
                        $c->html = $this->cleanIframe($embed->code->html);
                    }
                } catch (\TypeError $e) {
                    $this->logger->info('Embed:fetch: html prepare failed: '.$e->getMessage());
                }

                $this->logger->debug('Embed:fetch: fetch success, returning', [
                    'url' => $c->url,
                    'title' => $c->title,
                    'description' => $c->description,
                    'image' => $c->image,
                    'html' => $c->html,
                ]);

                return $c;
            }
        );
    }

    private function cleanIframe(?string $html): ?string
    {
        if (!$html || str_contains($html, 'wp-embedded-content')) {
            return null;
        }

        return $html;
    }

    public function getType(): string
    {
        if ($this->isImageUrl()) {
            return Entry::ENTRY_TYPE_IMAGE;
        }

        if ($this->isVideoUrl()) {
            return Entry::ENTRY_TYPE_IMAGE;
        }

        if ($this->isVideoEmbed()) {
            return Entry::ENTRY_TYPE_VIDEO;
        }

        return Entry::ENTRY_TYPE_LINK;
    }

    public function isImageUrl(): bool
    {
        if (!$this->url) {
            return false;
        }

        return ImageManager::isImageUrl($this->url);
    }

    private function isVideoUrl(): bool
    {
        return false;
    }

    private function isVideoEmbed(): bool
    {
        if (!$this->html) {
            return false;
        }

        return str_contains($this->html, 'video')
            || str_contains($this->html, 'youtube')
            || str_contains($this->html, 'vimeo')
            || str_contains($this->html, 'streamable'); // @todo
    }
}
