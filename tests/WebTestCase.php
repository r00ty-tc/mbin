<?php declare(strict_types = 1);

namespace App\Tests;

use App\Entity\Entry;
use App\Entity\Magazine;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as BaseWebTestCase;

abstract class WebTestCase extends BaseWebTestCase
{
    /**
     * @var ArrayCollection
     */
    protected $users;

    /**
     * @var ArrayCollection
     */
    protected $magazines;

    /**
     * @var ArrayCollection
     */
    protected $entries;

    public function __construct($name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        $this->users     = new ArrayCollection();
        $this->magazines = new ArrayCollection();
        $this->entries   = new ArrayCollection();
    }

    protected function loadExampleUsers(): void
    {
        foreach ($this->provideUsers() as $data) {
            $this->createUser($data['username'], $data['email'], $data['password']);
        }
    }

    protected function getUserByUsername(string $username): User
    {
        $user = $this->users->filter(
            static function (User $user) use ($username) {
                return $user->getUsername() === $username;
            }
        )->first();

        return $user ? $user : $this->createUser($username);
    }

    private function createUser(string $username, string $email = null, string $password = null): User
    {
        $manager = self::$container->get(EntityManagerInterface::class);

        $user = new User($email ? $email : $username.'@example.com', $username, $password ? $password : 'secret');

        $manager->persist($user);
        $manager->flush();

        $this->users->add($user);

        return $user;
    }

    protected function loadExampleMagazines(): void
    {
        $this->loadExampleUsers();

        foreach ($this->provideMagazines() as $data) {
            $this->createMagazine($data['name'], $data['title'], $data['user']);
        }
    }

    protected function getMagazineByName(string $name): Magazine
    {
        $magazine = $this->magazines->filter(
            static function (Magazine $magazine) use ($name) {
                return $magazine->getName() === $name;
            }
        )->first();

        return $magazine ? $magazine : $this->createMagazine($name);
    }

    private function createMagazine(string $name, string $title = null, User $user = null): Magazine
    {
        $manager = self::$container->get(EntityManagerInterface::class);

        $magazine = new Magazine($name, $title ?? 'Example magazine', $user ?? $this->getUserByUsername('regularUser'));

        $manager->persist($magazine);
        $manager->flush();

        $this->magazines->add($magazine);

        return $magazine;
    }

    protected function getEntryByTitle(string $title): Entry
    {
        $entry = $this->entries->filter(
            static function (Entry $entry) use ($title) {
                return $entry->getTitle() === $title;
            }
        )->first();

        if (!$entry) {
            $magazine = $this->getMagazineByName('polityka');
            $user     = $this->getUserByUsername('regularUser');
            $entry    = $this->createEntry($title, $magazine, $user);
        }

        return $entry;
    }

    private function createEntry(string $title, Magazine $magazine, User $user, string $url = null, string $body = null): Entry
    {
        $manager = self::$container->get(EntityManagerInterface::class);

        $entry = new Entry($title, $url, $body, $magazine, $user);

        $manager->persist($entry);
        $manager->flush();

        return $entry;
    }

    private function provideUsers(): iterable
    {
        yield [
            'username' => 'adminUser',
            'password' => 'adminUser123',
            'email'    => 'adminUser@example.com',
        ];

        yield [
            'username' => 'regularUser',
            'password' => 'regularUser123',
            'email'    => 'regularUser@example.com',
        ];
    }

    private function provideMagazines(): iterable
    {
        yield [
            'name'  => 'polityka',
            'title' => 'Magazyn polityczny',
            'user'  => $this->getUserByUsername('regularUser'),
        ];

        yield [
            'name'  => 'kbin',
            'title' => 'kbin devlog',
            'user'  => $this->getUserByUsername('adminUser'),
        ];
    }
}
