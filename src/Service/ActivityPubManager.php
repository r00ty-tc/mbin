<?php

declare(strict_types=1);

namespace App\Service;

use App\ActivityPub\Server;
use App\DTO\ActivityPub\ImageDto;
use App\DTO\ActivityPub\VideoDto;
use App\Entity\Contracts\ActivityPubActivityInterface;
use App\Entity\Contracts\ActivityPubActorInterface;
use App\Entity\Image;
use App\Entity\Magazine;
use App\Entity\Moderator;
use App\Entity\User;
use App\Exception\InvalidApPostException;
use App\Factory\ActivityPub\PersonFactory;
use App\Factory\MagazineFactory;
use App\Factory\UserFactory;
use App\Message\ActivityPub\UpdateActorMessage;
use App\Message\DeleteImageMessage;
use App\Repository\ImageRepository;
use App\Repository\MagazineRepository;
use App\Repository\UserRepository;
use App\Service\ActivityPub\ApHttpClient;
use App\Service\ActivityPub\Webfinger\WebFinger;
use App\Service\ActivityPub\Webfinger\WebFingerFactory;
use Doctrine\ORM\EntityManagerInterface;
use League\HTMLToMarkdown\HtmlConverter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ActivityPubManager
{
    public const USER_TYPES = [
        'Person',
        'Service',
        'Organization',
        'Application',
    ];

    public function __construct(
        private readonly Server $server,
        private readonly UserRepository $userRepository,
        private readonly UserManager $userManager,
        private readonly UserFactory $userFactory,
        private readonly MagazineManager $magazineManager,
        private readonly MagazineFactory $magazineFactory,
        private readonly MagazineRepository $magazineRepository,
        private readonly ApHttpClient $apHttpClient,
        private readonly ImageRepository $imageRepository,
        private readonly ImageManager $imageManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly PersonFactory $personFactory,
        private readonly SettingsManager $settingsManager,
        private readonly WebFingerFactory $webFingerFactory,
        private readonly MentionManager $mentionManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getActorProfileId(ActivityPubActorInterface $actor): string
    {
        /**
         * @var $actor User
         */
        if (!$actor->apId) {
            return $this->personFactory->getActivityPubId($actor);
        }

        // @todo blid webfinger
        return $actor->apProfileId;
    }

    public function findRemoteActor(string $actorUrl): ?User
    {
        return $this->userRepository->findOneBy(['apProfileId' => $actorUrl]);
    }

    public function createCcFromBody(string $body): array
    {
        $mentions = $this->mentionManager->extract($body) ?? [];

        $urls = [];
        foreach ($mentions as $handle) {
            try {
                $actor = $this->findActorOrCreate($handle);
            } catch (\Exception $e) {
                continue;
            }

            if (!$actor) {
                continue;
            }

            $urls[] = $actor->apProfileId ?? $this->urlGenerator->generate(
                'ap_user',
                ['username' => $actor->getUserIdentifier()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        }

        return $urls;
    }

    /**
     * Find an existing actor or create a new one if the actor doesn't yet exists.
     *
     * @param string $actorUrlOrHandle actor URL or actor handle
     *
     * @return null|User|Magazine or Magazine or null on error
     */
    public function findActorOrCreate(string $actorUrlOrHandle): null|User|Magazine
    {
        if (str_contains($actorUrlOrHandle, $this->settingsManager->get('KBIN_DOMAIN').'/m/')) {
            $magazine = str_replace('https://'.$this->settingsManager->get('KBIN_DOMAIN').'/m/', '', $actorUrlOrHandle);

            return $this->magazineRepository->findOneByName($magazine);
        }

        $actorUrl = $actorUrlOrHandle;
        if (false === filter_var($actorUrl, FILTER_VALIDATE_URL)) {
            if (!substr_count(ltrim($actorUrl, '@'), '@')) {
                return $this->userRepository->findOneBy(['username' => ltrim($actorUrl, '@')]);
            }

            $actorUrl = $this->webfinger($actorUrl)->getProfileId();
        }

        if (\in_array(
            parse_url($actorUrl, PHP_URL_HOST),
            [$this->settingsManager->get('KBIN_DOMAIN'), 'localhost', '127.0.0.1']
        )) {
            $name = explode('/', $actorUrl);
            $name = end($name);

            return $this->userRepository->findOneBy(['username' => $name]);
        }

        $actor = $this->apHttpClient->getActorObject($actorUrl);
        // Check if actor isn't empty (not set/null/empty array/etc.) and check if actor type is set
        if (!empty($actor) && isset($actor['type'])) {
            // User (we don't make a distinction between bots with type Service as Lemmy does)
            if (\in_array($actor['type'], self::USER_TYPES)) {
                $user = $this->userRepository->findOneBy(['apProfileId' => $actorUrl]);
                if (!$user) {
                    $user = $this->createUser($actorUrl);
                } else {
                    if (!$user->apFetchedAt || $user->apFetchedAt->modify('+1 hour') < (new \DateTime())) {
                        try {
                            $this->bus->dispatch(new UpdateActorMessage($user->apProfileId));
                        } catch (\Exception $e) {
                        }
                    }
                }

                return $user;
            }

            // Magazine (Group)
            if ('Group' === $actor['type']) {
                // User
                $magazine = $this->magazineRepository->findOneBy(['apProfileId' => $actorUrl]);
                if (!$magazine) {
                    $magazine = $this->createMagazine($actorUrl);
                } else {
                    if (!$magazine->apFetchedAt || $magazine->apFetchedAt->modify('+1 hour') < (new \DateTime())) {
                        try {
                            $this->bus->dispatch(new UpdateActorMessage($magazine->apProfileId));
                        } catch (\Exception $e) {
                        }
                    }
                }

                return $magazine;
            }
        }

        return null;
    }

    public function webfinger(string $id): WebFinger
    {
        $this->webFingerFactory::setServer($this->server->create());

        if (false === filter_var($id, FILTER_VALIDATE_URL)) {
            $id = ltrim($id, '@');

            return $this->webFingerFactory->get($id);
        }

        $handle = $this->buildHandle($id);

        return $this->webFingerFactory->get($handle);
    }

    public function buildHandle(string $id): string
    {
        $port = !\is_null(parse_url($id, PHP_URL_PORT))
            ? ':'.parse_url($id, PHP_URL_PORT)
            : '';

        return sprintf(
            '%s@%s%s',
            $this->apHttpClient->getActorObject($id)['preferredUsername'],
            parse_url($id, PHP_URL_HOST),
            $port
        );
    }

    /**
     * Creates a new user.
     *
     * @param string $actorUrl actor URL
     *
     * @return ?User or null on error
     */
    private function createUser(string $actorUrl): ?User
    {
        $webfinger = $this->webfinger($actorUrl);
        $this->userManager->create(
            $this->userFactory->createDtoFromAp($actorUrl, $webfinger->getHandle()),
            false,
            false
        );

        return $this->updateUser($actorUrl);
    }

    /**
     * Update existing user and return user object.
     *
     * @param string $actorUrl actor URL
     *
     * @return ?User or null on error (e.g. actor not found)
     */
    public function updateUser(string $actorUrl): ?User
    {
        $this->logger->info("updating user $actorUrl");
        $user = $this->userRepository->findOneBy(['apProfileId' => $actorUrl]);

        $actor = $this->apHttpClient->getActorObject($actorUrl);
        // Check if actor isn't empty (not set/null/empty array/etc.)
        if (!empty($actor)) {
            // Update the following user columns
            $user->type = $actor['type'] ?? 'Person';
            $user->apInboxUrl = $actor['endpoints']['sharedInbox'] ?? $actor['inbox'];
            $user->apDomain = parse_url($actor['id'], PHP_URL_HOST);
            $user->apFollowersUrl = $actor['followers'] ?? null;
            $user->apAttributedToUrl = $actor['attributedTo'] ?? null;
            $user->apPreferredUsername = $actor['preferredUsername'] ?? null;
            $user->apDiscoverable = $actor['discoverable'] ?? true;
            $user->apManuallyApprovesFollowers = $actor['manuallyApprovesFollowers'] ?? false;
            $user->apPublicUrl = $actor['url'] ?? $actorUrl;
            $user->apDeletedAt = null;
            $user->apTimeoutAt = null;
            $user->apFetchedAt = new \DateTime();

            // Only update about when summary is set
            if (isset($actor['summary'])) {
                $converter = new HtmlConverter(['strip_tags' => true]);
                $user->about = stripslashes($converter->convert($actor['summary']));
            }

            // Only update avatar if icon is set
            if (isset($actor['icon'])) {
                $newImage = $this->handleImages([$actor['icon']]);
                if ($user->avatar && $newImage !== $user->avatar) {
                    $this->bus->dispatch(new DeleteImageMessage($user->avatar->filePath));
                }
                $user->avatar = $newImage;
            }

            // Only update cover if image is set
            if (isset($actor['image'])) {
                $newImage = $this->handleImages([$actor['image']]);
                if ($user->cover && $newImage !== $user->cover) {
                    $this->bus->dispatch(new DeleteImageMessage($user->cover->filePath));
                }
                $user->cover = $newImage;
            }

            if($user->apFollowersUrl != null) {
                try {
                    $followersObj = $this->apHttpClient->getCollectionObject($user->apFollowersUrl);
                    if(isset($followersObj['totalItems']) and is_int($followersObj['totalItems'])) {
                        $user->apFollowersCount = $followersObj['totalItems'];
                        $user->updateFollowCounts();
                    }
                } catch (InvalidApPostException $ignored) { }
            }

            // Write to DB
            $this->entityManager->flush();

            return $user;
        } else {
            return null;
        }
    }

    public function handleImages(array $attachment): ?Image
    {
        $images = array_filter(
            $attachment,
            fn ($val) => \in_array($val['type'], ['Document', 'Image']) && ImageManager::isImageUrl($val['url'])
        ); // @todo multiple images

        if (\count($images)) {
            try {
                if ($tempFile = $this->imageManager->download($images[0]['url'])) {
                    $image = $this->imageRepository->findOrCreateFromPath($tempFile);
                    if ($image && isset($images[0]['name'])) {
                        $image->altText = $images[0]['name'];
                    }
                }
            } catch (\Exception $e) {
                return null;
            }

            return $image ?? null;
        }

        return null;
    }

    /**
     * Creates a new magazine (Group).
     *
     * @param string $actorUrl actor URL
     *
     * @return ?Magazine or null on error
     */
    private function createMagazine(string $actorUrl): ?Magazine
    {
        $this->magazineManager->create(
            $this->magazineFactory->createDtoFromAp($actorUrl, $this->buildHandle($actorUrl)),
            $this->userRepository->findAdmin(),
            false
        );

        return $this->updateMagazine($actorUrl);
    }

    /**
     * Update an existing magazine.
     *
     * @param string $actorUrl actor URL
     *
     * @return ?Magazine or null on error
     */
    public function updateMagazine(string $actorUrl): ?Magazine
    {
        $this->logger->info("updating magazine $actorUrl");
        $magazine = $this->magazineRepository->findOneBy(['apProfileId' => $actorUrl]);
        $actor = $this->apHttpClient->getActorObject($actorUrl);
        // Check if actor isn't empty (not set/null/empty array/etc.)
        if (!empty($actor)) {
            if (isset($actor['summary'])) {
                $converter = new HtmlConverter(['strip_tags' => true]);
                $magazine->description = stripslashes($converter->convert($actor['summary']));
            }

            if (isset($actor['icon'])) {
                $newImage = $this->handleImages([$actor['icon']]);
                if ($magazine->icon && $newImage !== $magazine->icon) {
                    $this->bus->dispatch(new DeleteImageMessage($magazine->icon->filePath));
                }
                $magazine->icon = $newImage;
            }

            if ($actor['preferredUsername']) {
                $magazine->title = $actor['preferredUsername'];
            }

            $magazine->apInboxUrl = $actor['endpoints']['sharedInbox'] ?? $actor['inbox'];
            $magazine->apDomain = parse_url($actor['id'], PHP_URL_HOST);
            $magazine->apFollowersUrl = $actor['followers'] ?? null;
            $magazine->apAttributedToUrl = $actor['attributedTo'] ?? null;
            $magazine->apPreferredUsername = $actor['preferredUsername'] ?? null;
            $magazine->apDiscoverable = $actor['discoverable'] ?? true;
            $magazine->apPublicUrl = $actor['url'] ?? $actorUrl;
            $magazine->apDeletedAt = null;
            $magazine->apTimeoutAt = null;
            $magazine->apFetchedAt = new \DateTime();
            $magazine->isAdult = (bool) $actor['sensitive'];

            if ($magazine->apFollowersUrl != null) {
                try {
                    $this->logger->info("updating remote followers of magazine $actorUrl");
                    $followersObj = $this->apHttpClient->getCollectionObject($magazine->apFollowersUrl);
                    if(isset($followersObj['totalItems']) and is_int($followersObj['totalItems'])) {
                        $magazine->apFollowersCount = $followersObj['totalItems'];
                        $magazine->updateSubscriptionsCount();
                    }
                } catch (InvalidApPostException $ignored) { }
            }

            if ($magazine->apAttributedToUrl != null) {
                try {
                    $this->logger->info("fetching moderators of remote magazine $actorUrl");
                    $attributedObj = $this->apHttpClient->getCollectionObject($magazine->apAttributedToUrl);
                    $items = null;
                    if (isset($attributedObj['items']) and is_array($attributedObj['items'])) {
                        $items = $attributedObj['items'];
                    } else if(isset($attributedObj['orderedItems']) and is_array($attributedObj['orderedItems'])) {
                        $items = $attributedObj['orderedItems'];
                    }

                    if($items != null) {
                        $moderatorsToRemove = [];
                        foreach($magazine->moderators as /** @var $mod Moderator */ $mod) {
                            if(!$mod->isOwner) {
                                $moderatorsToRemove[] = $mod->user;
                            }
                        }
                        $indexesNotToRemove = [];

                        foreach ($items as $item) {
                            if (is_string($item)) {
                                $user = $this->findActorOrCreate($item);
                                if ($user instanceof User) {
                                    foreach ($moderatorsToRemove as $key => $existMod) {
                                        if ($existMod->username == $user->username) {
                                            $indexesNotToRemove[] = $key;
                                            break;
                                        }
                                    }
                                    if(!$magazine->userIsModerator($user)) {
                                        $magazine->addModerator(new Moderator($magazine, $user, false, true));
                                    }
                                }
                            }
                        }

                        foreach ($indexesNotToRemove as $i) {
                            $moderatorsToRemove[$i] = null;
                        }

                        foreach ($moderatorsToRemove as $modToRemove) {
                            if ($modToRemove == null) {
                                continue;
                            }
                            $magazine->removeUserAsModerator($modToRemove);
                        }
                    } else {
                        $this->logger->warning("could not update the moderators of $actorUrl, the response doesn't have a 'items' or 'orderedItems' property or it is not an array");
                    }
                } catch (InvalidApPostException $ignored) { }
            }

            $this->entityManager->flush();

            return $magazine;
        } else {
            return null;
        }
    }

    public function createInboxesFromCC(array $activity, User $user): array
    {
        $followersUrl = $this->urlGenerator->generate(
            'ap_user_followers',
            ['username' => $user->username],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $arr = array_unique(
            array_filter(
                array_merge(
                    \is_array($activity['cc']) ? $activity['cc'] : [$activity['cc']],
                    \is_array($activity['to']) ? $activity['to'] : [$activity['to']]
                ), fn ($val) => !\in_array($val, [ActivityPubActivityInterface::PUBLIC_URL, $followersUrl, []])
            )
        );

        $users = [];
        foreach ($arr as $url) {
            if ($user = $this->findActorOrCreate($url)) {
                $users[] = $user;
            }
        }

        return array_map(fn ($user) => $user->apInboxUrl, $users);
    }

    public function handleVideos(array $attachment): ?VideoDto
    {
        $videos = array_filter(
            $attachment,
            fn ($val) => \in_array($val['type'], ['Document', 'Video']) && VideoManager::isVideoUrl($val['url'])
        );

        if (\count($videos)) {
            return (new VideoDto())->create(
                $videos[0]['url'],
                $videos[0]['mediaType'],
                !empty($videos['0']['name']) ? $videos['0']['name'] : $videos['0']['mediaType']
            );
        }

        return null;
    }

    public function handleExternalImages(array $attachment): ?array
    {
        $images = array_filter(
            $attachment,
            fn ($val) => \in_array($val['type'], ['Document', 'Image']) && ImageManager::isImageUrl($val['url'])
        );

        array_shift($images);

        if (\count($images)) {
            return array_map(fn ($val) => (new ImageDto())->create(
                $val['url'],
                $val['mediaType'],
                !empty($val['name']) ? $val['name'] : $val['mediaType']
            ), $images);
        }

        return null;
    }

    public function handleExternalVideos(array $attachment): ?array
    {
        $videos = array_filter(
            $attachment,
            fn ($val) => \in_array($val['type'], ['Document', 'Video']) && VideoManager::isVideoUrl($val['url'])
        );

        if (\count($videos)) {
            return array_map(fn ($val) => (new VideoDto())->create(
                $val['url'],
                $val['mediaType'],
                !empty($val['name']) ? $val['name'] : $val['mediaType']
            ), $videos);
        }

        return null;
    }

    /**
     * Update existing actor.
     *
     * @param string $actorUrl actor URL
     *
     * @return null|Magazine|User null on error
     */
    public function updateActor(string $actorUrl): null|Magazine|User
    {
        $this->logger->info("updating actor at $actorUrl");
        $actor = $this->apHttpClient->getActorObject($actorUrl);

        // User (We don't make a distinction between bots with type Service as Lemmy does)
        if (\in_array($actor['type'], self::USER_TYPES)) {
            return $this->updateUser($actorUrl);
        }

        return $this->updateMagazine($actorUrl);
    }
}
