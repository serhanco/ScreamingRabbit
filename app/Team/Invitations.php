<?php

namespace App\Team;

use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use App\Monitoring\Channels;

/**
 * Sends project invitations by e-mail through the shared sender mailbox. Someone new gets a sign-up link that also
 * joins the project; an existing member gets a link that adds the project to their account.
 */
class Invitations
{
    /**
     * @param  array<int, string>  $permissions
     * @return array{link: string, sent: bool|null, existing: bool} sent: true sent, false failed, null no mailbox set up
     */
    public static function send(Project $project, string $email, array $permissions, User $inviter): array
    {
        [$invitation, $token] = Invitation::issue($project, $email, $permissions, $inviter);
        $link = route('invitation.show', $token);
        $existing = $invitation->existingUser() !== null;

        $rights = $invitation->permissions === []
            ? 'Projeyi görüntüleyebileceksin (değişiklik yapamazsın).'
            : "Projeyi görüntüleyebilecek ve şunları yapabileceksin:\n- ".implode("\n- ", array_map(fn ($p) => Project::PERMISSIONS[$p], $invitation->permissions));
        $body = $existing
            ? "{$inviter->name} seni ScreamingRabbit'te \"{$project->name}\" projesine ekledi.\n\n{$rights}\n\nKabul etmek için hesabınla giriş yapıp bu bağlantıyı aç:\n{$link}"
            : "{$inviter->name} seni ScreamingRabbit'e ve \"{$project->name}\" projesine davet etti.\n\n{$rights}\n\nHesabını oluşturmak için bu bağlantıyı aç (ad ve şifre belirleyeceksin, ardından telefonuna iki adımlı doğrulama kurulur):\n{$link}";
        $body .= "\n\nBağlantı ".Invitation::VALID_DAYS.' gün geçerlidir.';

        $sent = Channels::email($invitation->email, $existing ? "Proje daveti: {$project->name}" : 'ScreamingRabbit daveti', $body);

        return ['link' => $link, 'sent' => $sent, 'existing' => $existing];
    }

    /** Whether the person already sees the project, so an invitation would change nothing. */
    public static function alreadyHasAccess(Project $project, string $email): bool
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        return $user !== null && ($user->isPrimary() || $project->owner_id === $user->id || $project->members()->whereKey($user->id)->exists());
    }
}
