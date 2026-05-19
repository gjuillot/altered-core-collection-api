<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Repository\CollectionCardViewRepository;
use App\State\CollectionProcessor;
use App\State\CollectionProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CollectionCardViewRepository::class)]
#[ORM\Table(name: 'collection_card_view')]
#[ORM\Index(name: 'idx_view_user', fields: ['user'])]
#[ORM\Index(name: 'idx_view_filters', fields: ['cardSet', 'faction', 'rarity'])]
#[ORM\Index(name: 'idx_view_variation', fields: ['variation'])]
#[ApiResource(
    shortName: 'Collection',
    operations: [
        new GetCollection(
            uriTemplate: '/collection',
            provider: CollectionProvider::class,
            normalizationContext: ['groups' => ['collection:read']],
        ),
        new Get(
            uriTemplate: '/collection/{id}',
            requirements: ['id' => '\d+'],
            provider: CollectionProvider::class,
            normalizationContext: ['groups' => ['collection:read']],
        ),
        new Post(
            uriTemplate: '/collection',
            processor: CollectionProcessor::class,
            denormalizationContext: ['groups' => ['collection:write']],
            normalizationContext: ['groups' => ['collection:read']],
        ),
        new Patch(
            uriTemplate: '/collection/{id}',
            requirements: ['id' => '\d+'],
            provider: CollectionProvider::class,
            processor: CollectionProcessor::class,
            denormalizationContext: ['groups' => ['collection:patch']],
            normalizationContext: ['groups' => ['collection:read']],
        ),
        new Delete(
            uriTemplate: '/collection/{id}',
            requirements: ['id' => '\d+'],
            provider: CollectionProvider::class,
            processor: CollectionProcessor::class,
        ),
    ],
    paginationEnabled: false,
)]
#[ApiFilter(SearchFilter::class, properties: [
    'cardSet'       => 'exact',
    'faction'       => 'exact',
    'rarity'        => 'exact',
    'cardReference' => 'partial',
    'name'          => 'partial',
    'cardType'      => 'exact',
    'variation'     => 'exact',
    'subTypes'      => 'partial',
])]
#[ApiFilter(RangeFilter::class, properties: ['mainCost', 'recallCost', 'oceanPower', 'mountainPower', 'forestPower'])]
#[ApiFilter(BooleanFilter::class, properties: ['isFoil', 'isBanned', 'isSuspended'])]
class CollectionCardView
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['collection:read'])]
    private ?int $id = null;

    /** Write model — deleted via this FK (ON DELETE CASCADE propagates to view). */
    #[ORM\OneToOne(targetEntity: CollectionCard::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private CollectionCard $collectionCard;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    // ── Input fields (set by client on POST) ──────────────────────────────────

    #[ORM\Column(length: 100)]
    #[Groups(['collection:read', 'collection:write'])]
    #[Assert\NotBlank(groups: ['collection:write'])]
    #[Assert\Regex(
        pattern: '/^ALT_[A-Z0-9]+_[A-Z0-9]+_[A-Z]+_\d+_[A-Z0-9]+(_\d+)?$/',
        message: 'Invalid card reference format. Expected: ALT_CORE_B_AX_01_C',
        groups: ['collection:write'],
    )]
    private string $cardReference;

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    #[Groups(['collection:read', 'collection:write', 'collection:patch'])]
    #[Assert\Range(min: 0, max: 99)]
    private int $quantity = 1;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['collection:read', 'collection:write', 'collection:patch'])]
    private bool $isFoil = false;

    // ── Read-only fields — populated from altered-core API on write ───────────

    /** set.reference from API (e.g. COREKS) */
    #[ORM\Column(length: 30)]
    #[Groups(['collection:read'])]
    private string $cardSet = '';

    /** faction.code from API (e.g. OR, BR, AX) */
    #[ORM\Column(length: 10)]
    #[Groups(['collection:read'])]
    private string $faction = '';

    /** cardRarity.reference from API (e.g. COMMON, RARE, UNIQUE) */
    #[ORM\Column(length: 20)]
    #[Groups(['collection:read'])]
    private string $rarity = '';

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['collection:read'])]
    private ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['collection:read'])]
    private ?string $imagePath = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['collection:read'])]
    private ?int $mainCost = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['collection:read'])]
    private ?int $recallCost = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['collection:read'])]
    private ?string $cardType = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['collection:read'])]
    private ?int $oceanPower = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['collection:read'])]
    private ?int $mountainPower = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    #[Groups(['collection:read'])]
    private ?int $forestPower = null;

    /** Printing variant: standard, alt-art, promo, kickstarter, serialized */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['collection:read'])]
    private ?string $variation = null;

    /** Comma-separated list of subtype references (e.g. "SOLDIER,MAGE") */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['collection:read'])]
    private ?string $subTypes = null;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['collection:read'])]
    private bool $isBanned = false;

    #[ORM\Column(options: ['default' => false])]
    #[Groups(['collection:read'])]
    private bool $isSuspended = false;

    #[ORM\Column]
    #[Groups(['collection:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['collection:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getCollectionCard(): CollectionCard { return $this->collectionCard; }
    public function setCollectionCard(CollectionCard $collectionCard): self { $this->collectionCard = $collectionCard; return $this; }

    public function getUser(): User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }

    public function getCardReference(): string { return $this->cardReference; }
    public function setCardReference(string $cardReference): self { $this->cardReference = $cardReference; return $this; }

    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $quantity): self { $this->quantity = $quantity; return $this; }

    public function isFoil(): bool { return $this->isFoil; }
    public function setIsFoil(bool $isFoil): self { $this->isFoil = $isFoil; return $this; }

    public function getCardSet(): string { return $this->cardSet; }
    public function setCardSet(string $cardSet): self { $this->cardSet = $cardSet; return $this; }

    public function getFaction(): string { return $this->faction; }
    public function setFaction(string $faction): self { $this->faction = $faction; return $this; }

    public function getRarity(): string { return $this->rarity; }
    public function setRarity(string $rarity): self { $this->rarity = $rarity; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name; return $this; }

    public function getImagePath(): ?string { return $this->imagePath; }
    public function setImagePath(?string $imagePath): self { $this->imagePath = $imagePath; return $this; }

    public function getMainCost(): ?int { return $this->mainCost; }
    public function setMainCost(?int $mainCost): self { $this->mainCost = $mainCost; return $this; }

    public function getRecallCost(): ?int { return $this->recallCost; }
    public function setRecallCost(?int $recallCost): self { $this->recallCost = $recallCost; return $this; }

    public function getCardType(): ?string { return $this->cardType; }
    public function setCardType(?string $cardType): self { $this->cardType = $cardType; return $this; }

    public function getOceanPower(): ?int { return $this->oceanPower; }
    public function setOceanPower(?int $oceanPower): self { $this->oceanPower = $oceanPower; return $this; }

    public function getMountainPower(): ?int { return $this->mountainPower; }
    public function setMountainPower(?int $mountainPower): self { $this->mountainPower = $mountainPower; return $this; }

    public function getForestPower(): ?int { return $this->forestPower; }
    public function setForestPower(?int $forestPower): self { $this->forestPower = $forestPower; return $this; }

    public function getVariation(): ?string { return $this->variation; }
    public function setVariation(?string $variation): self { $this->variation = $variation; return $this; }

    public function getSubTypes(): ?string { return $this->subTypes; }
    public function setSubTypes(?string $subTypes): self { $this->subTypes = $subTypes; return $this; }

    public function isBanned(): bool { return $this->isBanned; }
    public function setIsBanned(bool $isBanned): self { $this->isBanned = $isBanned; return $this; }

    public function isSuspended(): bool { return $this->isSuspended; }
    public function setIsSuspended(bool $isSuspended): self { $this->isSuspended = $isSuspended; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }

    /** Populate all card metadata fields from an altered-core API card response. */
    public function fillFromApiData(array $cardData, string $locale = 'fr'): void
    {
        $this->cardSet  = $cardData['set']['reference'] ?? '';
        $this->faction  = $cardData['faction']['code'] ?? '';
        $this->rarity   = $cardData['rarity']['reference'] ?? '';

        $this->name     = $cardData['name'] ?? null;

        $imageRaw        = $cardData['imagePath'] ?? null;
        $this->imagePath = is_array($imageRaw) ? ($imageRaw[$locale] ?? $imageRaw['fr'] ?? null) : $imageRaw;

        $this->mainCost   = isset($cardData['mainCost'])   ? (int) $cardData['mainCost']   : null;
        $this->recallCost = isset($cardData['recallCost']) ? (int) $cardData['recallCost'] : null;
        $this->cardType   = $cardData['cardType']['reference'] ?? (is_string($cardData['cardType'] ?? null) ? $cardData['cardType'] : null);

        $this->oceanPower    = isset($cardData['oceanPower'])    ? (int) $cardData['oceanPower']    : null;
        $this->mountainPower = isset($cardData['mountainPower']) ? (int) $cardData['mountainPower'] : null;
        $this->forestPower   = isset($cardData['forestPower'])   ? (int) $cardData['forestPower']   : null;
        $this->variation     = $cardData['variation'] ?? null;
        $this->isBanned      = (bool) ($cardData['isBanned'] ?? false);
        $this->isSuspended   = (bool) ($cardData['isSuspended'] ?? false);

        $rawSubTypes = $cardData['cardSubTypes'] ?? [];
        if (is_array($rawSubTypes) && !empty($rawSubTypes)) {
            $this->subTypes = implode(',', array_column($rawSubTypes, 'reference'));
        } else {
            $this->subTypes = null;
        }
    }
}
