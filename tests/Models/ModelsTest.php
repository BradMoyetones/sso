<?php

declare(strict_types=1);

namespace Fpjd\Sso\Test\Models;

use Fpjd\Sso\Database\AuthConnection;
use Fpjd\Sso\Models\Account;
use Fpjd\Sso\Models\Company;
use Fpjd\Sso\Models\Management;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModelsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // In-memory connection so relations can be built; no queries are executed.
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], AuthConnection::NAME);
        $capsule->bootEloquent();
    }

    /**
     * @return array<string, array{class-string<Model>, string}>
     */
    public static function models(): array
    {
        return [
            'account' => [Account::class, 'users_public'],
            'company' => [Company::class, 'companies_public'],
            'management' => [Management::class, 'managements_public'],
        ];
    }

    /**
     * @param class-string<Model> $class
     */
    #[DataProvider('models')]
    public function testModelUsesReadOnlyAuthConnection(string $class, string $table): void
    {
        $model = new $class();

        $this->assertSame(AuthConnection::NAME, $model->getConnectionName());
        $this->assertSame($table, $model->getTable());
        $this->assertSame('string', $model->getKeyType());
        $this->assertFalse($model->getIncrementing());
        $this->assertFalse($model->usesTimestamps());
    }

    public function testRelationsPointToRenamedModels(): void
    {
        $this->assertInstanceOf(Management::class, (new Account())->management()->getRelated());
        $this->assertInstanceOf(Company::class, (new Management())->company()->getRelated());
        $this->assertInstanceOf(Management::class, (new Company())->managements()->getRelated());
    }

    public function testAccountFullNameAccessor(): void
    {
        $account = (new Account())->forceFill(['first_name' => 'Jane', 'last_name' => 'Doe']);

        $this->assertSame('Jane Doe', $account->full_name);
    }

    public function testAccountCastsFlagsToBooleans(): void
    {
        $account = (new Account())->forceFill(['is_active' => 1, 'is_external' => 0]);

        $this->assertTrue($account->is_active);
        $this->assertFalse($account->is_external);
    }

    public function testLoadOrganizationToleratesEmptyInput(): void
    {
        Account::loadOrganization(null);
        Account::loadOrganization([]);
        Account::loadOrganization([null]);

        $this->addToAssertionCount(1);
    }
}
