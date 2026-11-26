{{-- Texte du visiteur affiché tel quel : échappé par Blade, jamais interprété comme du Markdown ou du HTML. --}}
<p>De : {{ $name }} &lt;{{ $email }}&gt;</p>
<p style="white-space: pre-wrap">{{ $body }}</p>
