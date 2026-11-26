<?php

namespace App\Livewire\Health\Exams\Concerns;

use App\Models\ProfileMember;
use App\Support\ProfileContext;
use Illuminate\Support\Collection;

/** As pessoas que quem olha pode ver na Saúde (o casal, ou quem ele acompanha) e quem está registrando. */
trait FindsExamPeople
{
    protected function people(): Collection
    {
        return ProfileMember::query()->visibleHere()->orderBy('role')->get();
    }

    protected function personOrFail(string $id): ProfileMember
    {
        return ProfileMember::query()->visibleHere()->findOrFail($id);
    }

    protected function author(): ?ProfileMember
    {
        return app(ProfileContext::class)->member();
    }

    /** A própria pessoa que está olhando, quando faz parte da lista; senão, a primeira. */
    protected function defaultPersonId(): string
    {
        $pessoas = $this->people();
        $minha = app(ProfileContext::class)->memberId();

        return (string) ($pessoas->firstWhere('id', $minha)?->id ?? $pessoas->first()?->id);
    }

    protected function canWriteFor(string $membroId): bool
    {
        return app(ProfileContext::class)->canWriteAsCaregiver($membroId !== '' ? $membroId : null);
    }
}
