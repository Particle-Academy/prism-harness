<?php

declare(strict_types=1);

namespace Prism\Harness\Facades;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Prism\Harness\PendingSession;
use Prism\Harness\PrismHarness as Manager;
use Prism\Harness\Sessions\Session;
use Prism\Harness\Sessions\SessionStoreManager;

/**
 * @method static PendingSession for(Model $participant)
 * @method static Session session(Model $participant, ?string $scope = null)
 * @method static SessionStoreManager stores()
 * @method static string defaultScope()
 *
 * @see Manager
 */
final class PrismHarness extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Manager::class;
    }
}
