<?php declare(strict_types=1);

namespace App\MessageHandler\ActivityPub\Inbox;

use App\Entity\Magazine;
use App\Entity\User;
use App\Message\ActivityPub\Inbox\FollowMessage;
use App\Service\ActivityPub\ApHttpClient;
use App\Service\ActivityPub\Wrapper\AcceptWrapper;
use App\Service\ActivityPubManager;
use App\Service\MagazineManager;
use App\Service\UserManager;
use JetBrains\PhpStorm\ArrayShape;
use LogicException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class FollowHandler implements MessageHandlerInterface
{
    public function __construct(
        private ActivityPubManager $activityPubManager,
        private UserManager $userManager,
        private MagazineManager $magazineManager,
        private ApHttpClient $client,
        private AcceptWrapper $acceptWrapper
    ) {
    }

    public function __invoke(FollowMessage $message)
    {
        $actor = $this->activityPubManager->findActorOrCreate($message->payload['actor']);

        if ($message->payload['type'] === 'Follow') {
            $object = $this->activityPubManager->findActorOrCreate($message->payload['object']);

            $this->handleFollow($object, $actor);

            // @todo group follow accept
            if($object instanceof User) {
                $this->accept($message->payload, $object);
            }

            return;
        }

        if (isset($message->payload['object'])) {
            switch ($message->payload['type']) {
                case 'Undo':
                    $this->handleUnfollow(
                        $this->activityPubManager->findActorOrCreate($message->payload['object']['object']),
                        $actor
                    );
                    break;
                case 'Accept':
                    $this->handleAccept(
                        $actor,
                        $this->activityPubManager->findActorOrCreate($message->payload['object']['actor'])
                    );
                    break;
                case 'Reject':
                    $this->handleReject(
                        $actor,
                        $this->activityPubManager->findActorOrCreate($message->payload['object']['actor'])
                    );
                    break;
                default:
                    break;
            }
        }
    }

    #[ArrayShape([
        '@context' => "string",
        'id' => "string",
        'type' => "string",
        'actor' => "mixed",
        'object' => "mixed",
    ])] private function accept(
        array $payload,
        User $object
    ): void {
        $accept = $this->acceptWrapper->build(
            $payload['object'],
            $payload['actor'],
            $payload['id'],
        );

        $this->client->post($this->client->getInboxUrl($payload['actor']), $object, $accept);
    }

    private function handleFollow(User|Magazine $object, User $actor): void
    {
        match (true) {
            $object instanceof User => $this->userManager->follow($actor, $object),
            $object instanceof Magazine => $this->magazineManager->subscribe($object, $actor),
            default => throw new LogicException(),
        };
    }

    private function handleUnfollow(User|Magazine $object, User $actor): void
    {
        match (true) {
            $object instanceof User => $this->userManager->unfollow($actor, $object),
            $object instanceof Magazine => $this->magazineManager->unsubscribe($object, $actor),
            default => throw new LogicException(),
        };
    }

    private function handleAccept(User $actor, User $object): void
    {
        $this->userManager->acceptFollow($object, $actor);
    }

    private function handleReject(User $actor, User $object): void
    {
        $this->userManager->rejectFollow($object, $actor);
    }
}
