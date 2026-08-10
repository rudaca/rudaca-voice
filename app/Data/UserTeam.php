<?php

namespace App\Data;

readonly class UserTeam
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public bool $isPersonal,
        public ?string $role,
        public ?string $roleLabel,
        public bool $canManage = false,
        public bool $canLeave = false,
        public ?bool $isCurrent = null,
    ) {
        //
    }
}
