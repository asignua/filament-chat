<?php

declare(strict_types=1);

namespace Workbench\App\Policies;

use Workbench\App\Models\Note;
use Workbench\App\Models\User;

/**
 * A note without an owner is public; an owned one — for its owner only.
 */
class NotePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Note $note): bool
    {
        return $note->owner_id === null || $note->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Note $note): bool
    {
        return $this->view($user, $note);
    }

    public function delete(User $user, Note $note): bool
    {
        return $this->view($user, $note);
    }
}
