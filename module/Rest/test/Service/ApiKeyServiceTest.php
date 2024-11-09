<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Rest\Service;

use Cake\Chronos\Chronos;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shlinkio\Shlink\Common\Exception\InvalidArgumentException;
use Shlinkio\Shlink\Core\Domain\Entity\Domain;
use Shlinkio\Shlink\Core\Model\Renaming;
use Shlinkio\Shlink\Rest\ApiKey\Model\ApiKeyMeta;
use Shlinkio\Shlink\Rest\ApiKey\Model\RoleDefinition;
use Shlinkio\Shlink\Rest\ApiKey\Repository\ApiKeyRepositoryInterface;
use Shlinkio\Shlink\Rest\Entity\ApiKey;
use Shlinkio\Shlink\Rest\Service\ApiKeyService;

use function substr;

class ApiKeyServiceTest extends TestCase
{
    private ApiKeyService $service;
    private MockObject & EntityManager $em;
    private MockObject & ApiKeyRepositoryInterface $repo;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManager::class);
        $this->repo = $this->createMock(ApiKeyRepositoryInterface::class);
        $this->service = new ApiKeyService($this->em, $this->repo);
    }

    /**
     * @param RoleDefinition[] $roles
     */
    #[Test, DataProvider('provideCreationDate')]
    public function apiKeyIsProperlyCreated(Chronos|null $date, string|null $name, array $roles): void
    {
        $this->repo->expects($this->once())->method('count')->with(
            ! empty($name) ? ['name' => $name] : $this->isType('array'),
        )->willReturn(0);
        $this->em->expects($this->once())->method('flush');
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(ApiKey::class));

        $meta = ApiKeyMeta::fromParams(name: $name, expirationDate: $date, roleDefinitions: $roles);
        $key = $this->service->create($meta);

        self::assertEquals($date, $key->expirationDate);
        self::assertEquals(
            empty($name) ? substr($meta->key, 0, 8) . '-****-****-****-************' : $name,
            $key->name,
        );
        foreach ($roles as $roleDefinition) {
            self::assertTrue($key->hasRole($roleDefinition->role));
        }
    }

    public static function provideCreationDate(): iterable
    {
        $domain = Domain::withAuthority('');
        $domain->setId('123');

        yield 'no expiration date or name' => [null, null, []];
        yield 'expiration date' => [Chronos::parse('2030-01-01'), null, []];
        yield 'roles' => [null, null, [
            RoleDefinition::forDomain($domain),
            RoleDefinition::forAuthoredShortUrls(),
        ]];
        yield 'single name' => [null, 'Alice', []];
        yield 'multi-word name' => [null, 'Alice and Bob', []];
        yield 'empty name' => [null, '', []];
    }

    #[Test]
    public function exceptionIsThrownWhileCreatingIfNameIsInUse(): void
    {
        $this->repo->expects($this->once())->method('count')->with(['name' => 'the_name'])->willReturn(1);
        $this->em->expects($this->never())->method('flush');
        $this->em->expects($this->never())->method('persist');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Another API key with name "the_name" already exists');

        $this->service->create(ApiKeyMeta::fromParams(name: 'the_name'));
    }

    #[Test, DataProvider('provideInvalidApiKeys')]
    public function checkReturnsFalseForInvalidApiKeys(ApiKey|null $invalidKey): void
    {
        $this->repo->expects($this->once())->method('findOneBy')->with(['key' => ApiKey::hashKey('12345')])->willReturn(
            $invalidKey,
        );

        $result = $this->service->check('12345');

        self::assertFalse($result->isValid());
        self::assertSame($invalidKey, $result->apiKey);
    }

    public static function provideInvalidApiKeys(): iterable
    {
        yield 'non-existent api key' => [null];
        yield 'disabled api key' => [ApiKey::create()->disable()];
        yield 'expired api key' => [
            ApiKey::fromMeta(ApiKeyMeta::fromParams(expirationDate: Chronos::now()->subDays(1))),
        ];
    }

    #[Test]
    public function checkReturnsTrueWhenConditionsAreFavorable(): void
    {
        $apiKey = ApiKey::create();

        $this->repo->expects($this->once())->method('findOneBy')->with(['key' => ApiKey::hashKey('12345')])->willReturn(
            $apiKey,
        );

        $result = $this->service->check('12345');

        self::assertTrue($result->isValid());
        self::assertSame($apiKey, $result->apiKey);
    }

    #[Test, DataProvider('provideDisableArgs')]
    public function disableThrowsExceptionWhenNoApiKeyIsFound(string $disableMethod, array $findOneByArg): void
    {
        $this->repo->expects($this->once())->method('findOneBy')->with($findOneByArg)->willReturn(null);

        $this->expectException(InvalidArgumentException::class);

        $this->service->{$disableMethod}('12345');
    }

    #[Test, DataProvider('provideDisableArgs')]
    public function disableReturnsDisabledApiKeyWhenFound(string $disableMethod, array $findOneByArg): void
    {
        $key = ApiKey::create();
        $this->repo->expects($this->once())->method('findOneBy')->with($findOneByArg)->willReturn($key);
        $this->em->expects($this->once())->method('flush');

        self::assertTrue($key->isEnabled());
        $returnedKey = $this->service->{$disableMethod}('12345');
        self::assertFalse($key->isEnabled());
        self::assertSame($key, $returnedKey);
    }

    public static function provideDisableArgs(): iterable
    {
        yield 'disableByKey' => ['disableByKey', ['key' => ApiKey::hashKey('12345')]];
        yield 'disableByName' => ['disableByName', ['name' => '12345']];
    }

    #[Test]
    public function listFindsAllApiKeys(): void
    {
        $expectedApiKeys = [ApiKey::create(), ApiKey::create(), ApiKey::create()];

        $this->repo->expects($this->once())->method('findBy')->with([])->willReturn($expectedApiKeys);

        $result = $this->service->listKeys();

        self::assertEquals($expectedApiKeys, $result);
    }

    #[Test]
    public function listEnabledFindsOnlyEnabledApiKeys(): void
    {
        $expectedApiKeys = [ApiKey::create(), ApiKey::create(), ApiKey::create()];

        $this->repo->expects($this->once())->method('findBy')->with(['enabled' => true])->willReturn($expectedApiKeys);

        $result = $this->service->listKeys(enabledOnly: true);

        self::assertEquals($expectedApiKeys, $result);
    }

    #[Test, DataProvider('provideInitialApiKeys')]
    public function createInitialDelegatesToRepository(ApiKey|null $apiKey): void
    {
        $this->repo->expects($this->once())->method('createInitialApiKey')->with('the_key')->willReturn($apiKey);

        $result = $this->service->createInitial('the_key');

        self::assertSame($result, $apiKey);
    }

    public static function provideInitialApiKeys(): iterable
    {
        yield 'first api key' => [ApiKey::create()];
        yield 'existing api keys' => [null];
    }

    #[Test]
    public function renameApiKeyThrowsExceptionIfApiKeyIsNotFound(): void
    {
        $renaming = Renaming::fromNames(oldName: 'old', newName: 'new');

        $this->repo->expects($this->once())->method('findOneBy')->with(['name' => 'old'])->willReturn(null);
        $this->repo->expects($this->never())->method('count');
        $this->em->expects($this->never())->method('flush');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('API key with name "old" could not be found');

        $this->service->renameApiKey($renaming);
    }

    #[Test]
    public function renameApiKeyReturnsApiKeyVerbatimIfBothNamesAreEqual(): void
    {
        $renaming = Renaming::fromNames(oldName: 'same_value', newName: 'same_value');
        $apiKey = ApiKey::create();

        $this->repo->expects($this->once())->method('findOneBy')->with(['name' => 'same_value'])->willReturn($apiKey);
        $this->repo->expects($this->never())->method('count');
        $this->em->expects($this->never())->method('flush');

        $result = $this->service->renameApiKey($renaming);

        self::assertSame($apiKey, $result);
    }

    #[Test]
    public function renameApiKeyThrowsExceptionIfNewNameIsInUse(): void
    {
        $renaming = Renaming::fromNames(oldName: 'old', newName: 'new');
        $apiKey = ApiKey::create();

        $this->repo->expects($this->once())->method('findOneBy')->with(['name' => 'old'])->willReturn($apiKey);
        $this->repo->expects($this->once())->method('count')->with(['name' => 'new'])->willReturn(1);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Another API key with name "new" already exists');

        $this->service->renameApiKey($renaming);
    }

    #[Test]
    public function renameApiKeyReturnsApiKeyWithNewName(): void
    {
        $renaming = Renaming::fromNames(oldName: 'old', newName: 'new');
        $apiKey = ApiKey::fromMeta(ApiKeyMeta::fromParams(name: 'old'));

        $this->repo->expects($this->once())->method('findOneBy')->with(['name' => 'old'])->willReturn($apiKey);
        $this->repo->expects($this->once())->method('count')->with(['name' => 'new'])->willReturn(0);
        $this->em->expects($this->once())->method('flush');

        $result = $this->service->renameApiKey($renaming);

        self::assertSame($apiKey, $result);
        self::assertEquals('new', $apiKey->name);
    }
}
