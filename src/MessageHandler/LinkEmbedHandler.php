<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\LinkEmbedMessage;
use App\Repository\EmbedRepository;
use App\Utils\Embed;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class LinkEmbedHandler
{
    public function __construct(
        private readonly EmbedRepository $embedRepository,
        private readonly Embed $embed,
        private readonly CacheItemPoolInterface $markdownCache
    ) {
    }

    public function __invoke(LinkEmbedMessage $message): void
    {
        preg_match_all('#\bhttps?://[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#', $message->body, $match);

        foreach ($match[0] as $url) {
            $existingEmbed = $this->embedRepository->findOneBy(['url' => $url]);
            try {
                $embed = $this->embed->fetch($url)->html;
                if ($embed) {
                    if ($existingEmbed) {
                        $existingEmbed->hasEmbed = true;
                        $this->entityManager->flush();
                    } else {
                        $entity = new \App\Entity\Embed($url, true);
                        $this->embedRepository->add($entity);
                    }
                }
            } catch (\Exception $e) {
                $embed = false;
            }

            if (!$embed) {

                if ($existingEmbed) {
                    $existingEmbed->hasEmbed = false;
                    $this->entityManager->flush();
                } else {
                    $entity = new \App\Entity\Embed($url, false);
                    $this->embedRepository->add($entity);
                }
            }
        }

        $this->markdownCache->deleteItem(hash('sha256', json_encode(['content' => $message->body])));
    }
}
