<x-mail::message>
# {{ $organizationName }} への招待

@if ($inviterName)
{{ $inviterName }} さんから、STOC MEO の「{{ $organizationName }}」に **{{ $roleLabel }}** として招待されました。
@else
STOC MEO の「{{ $organizationName }}」に **{{ $roleLabel }}** として招待されました。
@endif

下のボタンから招待を承諾してください。

<x-mail::button :url="$acceptUrl">
招待を承諾する
</x-mail::button>

この招待は {{ $expiresAt->timezone(config('app.timezone'))->format('Y年n月j日 H:i') }} まで有効です。
心当たりがない場合は、このメールを破棄してください。

<x-mail::subcopy>
ボタンが動作しない場合は、次のURLをブラウザに貼り付けてください： {{ $acceptUrl }}
</x-mail::subcopy>
</x-mail::message>
