<?php

namespace App\Tests\Unit\Controller;

use App\Controller\CollectionPlaysetCardsController;
use App\Entity\User;
use App\Service\CollectionPlaysetCardsService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CollectionPlaysetCardsControllerTest extends TestCase
{
    private Security                         $security;
    private CollectionPlaysetCardsService    $service;
    private CollectionPlaysetCardsController $controller;
    private User                             $user;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->service  = $this->createMock(CollectionPlaysetCardsService::class);

        $this->user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($this->user);

        $this->controller = new CollectionPlaysetCardsController($this->security, $this->service);
    }

    private function request(string $queryString = ''): Request
    {
        return Request::create('/api/collection/playset/cards' . ($queryString ? '?' . $queryString : ''));
    }

    public function testReturnsServiceResultAsJson(): void
    {
        $payload = [
            'items'        => [['baseReference' => 'ALT_DUSTER_B_AX_88', 'name' => 'Ira', 'cardSet' => 'DUSTER', 'cardType' => 'CHARACTER', 'versions' => []]],
            'page'         => 1,
            'itemsPerPage' => 30,
            'totalItems'   => 1,
            'totalPages'   => 1,
        ];

        $this->service->expects($this->once())
            ->method('listCards')
            ->willReturn($payload);

        $response = $this->controller->__invoke($this->request('locale=en&cardSet[]=DUSTER'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('ALT_DUSTER_B_AX_88', $data['items'][0]['baseReference']);
        $this->assertSame(1, $data['totalItems']);
    }

    public function testForwardsQueryParametersToService(): void
    {
        $this->service->expects($this->once())
            ->method('listCards')
            ->with(
                $this->identicalTo($this->user),
                'fr',
                ['DUSTER'],
                ['BR'],
                ['CHARACTER'],
                ['RARE'],
                'ira',
                ['3'],
                2,
                50,
            )
            ->willReturn(['items' => [], 'page' => 2, 'itemsPerPage' => 50, 'totalItems' => 0, 'totalPages' => 0]);

        $response = $this->controller->__invoke($this->request(
            'locale=fr&cardSet[]=DUSTER&faction[]=BR&cardType[]=CHARACTER&rarity[]=RARE&name=ira&copies[]=3&page=2&itemsPerPage=50',
        ));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testRejectsInvalidRarityWith400(): void
    {
        $this->service->expects($this->never())->method('listCards');

        $response = $this->controller->__invoke($this->request('rarity[]=COMMON&rarity[]=UNIQUE'));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertStringContainsString('UNIQUE', json_decode($response->getContent(), true)['error']);
    }

    public function testRejectsInvalidCopiesWith400(): void
    {
        $this->service->expects($this->never())->method('listCards');

        $response = $this->controller->__invoke($this->request('copies[]=99'));

        $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertStringContainsString('99', json_decode($response->getContent(), true)['error']);
    }
}
