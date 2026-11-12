{{--
    Resumo de TODOS os erros de validação do formulário, junto do botão de salvar. Sem isto, um erro num campo que não aparece na tela (ou fora da área
    visível da janela) deixava o "Salvar" parecendo morto: a requisição voltava recusada e nada indicava o porquê.
--}}
@if ($errors->any())
    <div class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200" role="alert" data-form-errors>
        <p class="font-medium">Não foi possível salvar. Confira:</p>
        <ul class="mt-1 list-disc space-y-0.5 pl-5 text-xs">
            @foreach (array_unique($errors->all()) as $mensagem)
                <li>{{ $mensagem }}</li>
            @endforeach
        </ul>
    </div>
@endif
