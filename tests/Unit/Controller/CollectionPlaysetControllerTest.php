<?php

namespace App\Tests\Unit\Controller;

use App\Controller\CollectionPlaysetController;
use App\Entity\User;
use App\Service\CollectionPlaysetService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CollectionPlaysetControllerTest extends TestCase
{
    private Security                    $security;
    private CollectionPlaysetService    $playsetService;
    private CollectionPlaysetController $controller;
    private User                        $user;

    protected function setUp(): void
    {
        $this->security       = $this->createMock(Security::class);
        $this->playsetService = $this->createMock(CollectionPlaysetService::class);

        $this->user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($this->user);

        $this->controller = new CollectionPlaysetController($this->security, $this->playsetService);
    }

    public function testReturnsPlaysetAsJsonForConnectedUser(): void
    {
        $playset = [
            'byFactionAndSet' => [
                ['faction' => 'AX', 'cardSet' => 'ALIZE', 'quantities' => ['0' => 23, '1' => 4, '2' => 57, '3+' => 57]],
            ],
            'byFaction' => [
                ['faction' => 'AX', 'quantities' => ['0' => 23, '1' => 4, '2' => 57, '3+' => 57]],
            ],
            'bySet' => [
                ['cardSet' => 'ALIZE', 'quantities' => ['0' => 23, '1' => 4, '2' => 57, '3+' => 57]],
            ],
        ];

        $this->playsetService->expects($this->once())
            ->method('computePlayset')
            ->with($this->user, null)
            ->willReturn($playset);

        $response = $this->controller->__invoke(Request::create('/api/collection/playset'));

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('AX', $data['byFactionAndSet'][0]['faction']);
        $this->assertSame('ALIZE', $data['byFactionAndSet'][0]['cardSet']);
        $this->assertSame(23, $data['byFactionAndSet'][0]['quantities']['0']);
        $this->assertSame('AX', $data['byFaction'][0]['faction']);
        $this->assertSame('ALIZE', $data['bySet'][0]['cardSet']);
        $this->assertSame(57, $data['bySet'][0]['quantities']['3+']);
    }

    public function testForwardsRaritySubsetFromQueryToService(): void
    {
        $this->playsetService->expects($this->once())
            ->method('computePlayset')
            ->with($this->user, ['COMMON', 'RARE'])
            ->willReturn(['byFactionAndSet' => [], 'byFaction' => [], 'bySet' => []]);

        $response = $this->controller->__invoke(
            Request::create('/api/collection/playset?rarity[]=COMMON&rarity[]=RARE'),
        );

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testDeduplicatesRepeatedRarities(): void
    {
        $this->playsetService->expects($this->once())
            ->method('computePlayset')
            ->with($this->user, ['EXALTED'])
            ->willReturn(['byFactionAndSet' => [], 'byFaction' => [], 'bySet' => []]);

        $this->controller->__invoke(
            Request::create('/api/collection/playset?rarity[]=EXALTED&rarity[]=EXALTED'),
        );
    }

    public function testRejectsUnknownRarityWith422AndNeverCallsService(): void
    {
        $this->playsetService->expects($this->never())->method('computePlayset');

        $response = $this->controller->__invoke(
            Request::create('/api/collection/playset?rarity[]=COMMON&rarity[]=UNIQUE'),
        );

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('UNIQUE', $data['error']);
    }
}
