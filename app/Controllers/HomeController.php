<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Repositories\NodeRepository;
use App\Repositories\ProfileRepository;

final class HomeController
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly ProfileRepository $profiles,
        private readonly ContentPageController $contentPages,
    ) {
    }

    public function index(): string
    {
        $node = $this->nodes->findFirst();
        $profile = $node !== null ? $this->profiles->findByNodeId((int) $node['id']) : null;

        if ($profile === null || $profile['visibility'] !== 'PUBLIC') {
            return $this->placeholder();
        }

        return $this->contentPages->profile((string) $profile['handle']);
    }

    public function aboutMe(): string
    {
        return $this->ownerPage('aboutMe');
    }

    public function youtube(): string
    {
        return $this->ownerPage('youtube');
    }

    public function wallCoretan(): string
    {
        return $this->ownerPage('wallCoretan');
    }

    private function ownerPage(string $page): string
    {
        $node = $this->nodes->findFirst();
        $profile = $node !== null ? $this->profiles->findByNodeId((int) $node['id']) : null;
        if ($profile === null || $profile['visibility'] !== 'PUBLIC') {
            return $this->placeholder();
        }
        return $this->contentPages->{$page}((string) $profile['handle']);
    }

    private function placeholder(): string
    {
        return View::render('home', [
            'title' => 'FPDP',
            'heading' => 'Personal Digital Home',
            'subtitle' => 'Your domain becomes your digital home.',
        ]);
    }
}
