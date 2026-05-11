<?php

namespace Kompo\Auth\Contracts\Security;

use Condoedge\Utils\Contracts\Security\HasOwnedRecords as BaseHasOwnedRecords;

/**
 * Auth-side alias of `Condoedge\Utils\Contracts\Security\HasOwnedRecords`.
 *
 * The canonical contract lives in utils so models that don't depend on auth
 * (Phone, Email, Address, File) can implement it. Auth-side consumers may
 * keep importing this name — every class implementing the utils interface
 * also satisfies this one because of the `extends`.
 */
interface HasOwnedRecords extends BaseHasOwnedRecords
{
}
