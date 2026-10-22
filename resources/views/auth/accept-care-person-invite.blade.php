<x-layouts.guest title="Seu espaço de saúde · Cerne">
    @if ($modo === 'invalido')
        <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Convite indisponível</h1>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
            Este convite expirou, foi cancelado ou já foi utilizado. Peça um novo a quem te convidou.
        </p>
        <a href="{{ route('login') }}" class="mt-6 inline-block text-sm text-brand-800 dark:text-brand-300 hover:underline">Ir para o login</a>
    @else
        <h1 class="text-lg font-semibold text-slate-900 dark:text-white">Olá, {{ $convite->member->name }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ $convite->invitedBy?->name }} criou no Cerne um espaço para guardar as suas informações de saúde e ajudar a cuidar de você.
            Com o seu acesso, o espaço passa a ser também seu.
        </p>
        <ul class="mt-3 list-disc space-y-1 pl-5 text-xs text-slate-500 dark:text-slate-400">
            <li>Você vê tudo o que está registrado sobre você (consultas, remédios, alergias, documentos).</li>
            <li>Você vê quem tem acesso e pode encerrar o acesso de qualquer pessoa que cuida de você.</li>
            <li>Você pode apagar o seu espaço inteiro quando quiser.</li>
            <li>Você não paga nada: quem criou o espaço continua responsável por ele.</li>
        </ul>

        @if ($modo === 'criar')
            <form method="POST" action="{{ route('care-person-invite.store', ['token' => $token]) }}" class="mt-6 space-y-5">
                @csrf
                <div>
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300">E-mail</label>
                    <input type="email" value="{{ $convite->email }}" autocomplete="username" disabled
                        class="mt-1 block w-full rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-700 px-3 py-2 text-sm text-slate-500 dark:text-slate-400">
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Senha</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" required class="input mt-1.5">
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Mínimo de 8 caracteres, com letras e números.</p>
                    @error('password') <p class="mt-1 text-sm text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-slate-700 dark:text-slate-300">Confirme a senha</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="input mt-1.5">
                </div>
                <label class="flex items-start gap-3 text-sm text-slate-700 dark:text-slate-300">
                    <input type="checkbox" name="terms" value="1" class="mt-1 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span>Li e aceito os <a href="{{ route('legal.terms') }}" target="_blank" class="text-brand-800 underline dark:text-brand-300">Termos de Uso</a> e a <a href="{{ route('legal.privacy') }}" target="_blank" class="text-brand-800 underline dark:text-brand-300">Política de Privacidade</a>, e consinto com o tratamento dos meus dados de saúde neste espaço.</span>
                </label>
                @error('terms') <p class="text-sm text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                <button type="submit" class="w-full btn-primary">Criar o meu acesso</button>
            </form>
        @elseif ($modo === 'confirmar')
            <form method="POST" action="{{ route('care-person-invite.store', ['token' => $token]) }}" class="mt-6">
                @csrf
                @error('password') <p class="mb-3 text-sm text-red-700 dark:text-red-400">{{ $message }}</p> @enderror
                <button type="submit" class="w-full btn-primary">Aceitar e ver o meu espaço</button>
            </form>
        @elseif ($modo === 'outra_conta')
            <p class="mt-6 text-sm text-slate-600 dark:text-slate-300">
                Você está conectado com outra conta. Este convite é para <strong>{{ $convite->email }}</strong>: saia e entre com esse e-mail para aceitar.
            </p>
            <form method="POST" action="{{ route('logout') }}" class="mt-3">
                @csrf
                <button type="submit" class="btn-secondary w-full">Sair desta conta</button>
            </form>
        @else
            <p class="mt-6 text-sm text-slate-600 dark:text-slate-300">
                Já existe uma conta com o e-mail <strong>{{ $convite->email }}</strong>. Entre com ela para aceitar o convite.
            </p>
            <a href="{{ route('login') }}" class="btn-primary mt-4 block w-full text-center">Entrar para aceitar</a>
        @endif
    @endif
</x-layouts.guest>
