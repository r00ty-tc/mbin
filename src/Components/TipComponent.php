<?php declare(strict_types=1);

namespace App\Components;

use App\Entity\Contracts\ContentInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('tip')]
class TipComponent
{
    public ContentInterface $subject;
    public array $transactions = [];
    public string $key = '';

    public function __construct()
    {
        $this->key = (string) rand(0, 200);
    }
}