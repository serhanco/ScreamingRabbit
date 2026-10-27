{{-- The page behind an invitation link: sign up, sign in, or accept. Kept standalone so it works without a panel session. --}}
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <title>Davet · ScreamingRabbit</title>
    <style>
        body { font-family: system-ui, -apple-system, 'Segoe UI', Arial, sans-serif; background: #f3f4f6; color: #1f2937; margin: 0; padding: 16px; }
        .card { max-width: 440px; margin: 48px auto; background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 1px 3px rgba(0, 0, 0, .08); }
        h1 { font-size: 20px; margin: 0 0 12px; }
        p { line-height: 1.6; margin: 0 0 12px; }
        ul { margin: 0 0 16px; padding-left: 20px; line-height: 1.6; }
        label { display: block; font-size: 14px; font-weight: 600; margin: 12px 0 4px; }
        input { box-sizing: border-box; width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 15px; }
        .btn { display: inline-block; margin-top: 16px; padding: 10px 18px; border: 0; border-radius: 8px; background: #ea580c; color: #fff; font-size: 15px; font-weight: 600; text-decoration: none; cursor: pointer; }
        .error { color: #b91c1c; font-size: 13px; margin-top: 4px; }
        .muted { color: #6b7280; font-size: 13px; }
    </style>
</head>
<body>
<div class="card">
    @if ($state === 'invalid')
        <h1>Bu davet geçerli değil</h1>
        <p>Bağlantı kullanılmış ya da süresi dolmuş olabilir. Seni davet eden kişiden yeni bir davet iste.</p>
    @else
        <h1>"{{ $invitation->project->name }}" projesine davet</h1>
        <p>{{ $invitation->inviter?->name ?? 'Bir yönetici' }} seni bu projeye ekliyor.</p>
        @if ($invitation->permissions === [])
            <p>Projeyi görüntüleyebileceksin; değişiklik yapamazsın.</p>
        @else
            <p>Projeyi görüntüleyebilecek ve şunları yapabileceksin:</p>
            <ul>
                @foreach ($invitation->permissions as $permission)
                    <li>{{ \App\Models\Project::PERMISSIONS[$permission] ?? $permission }}</li>
                @endforeach
            </ul>
        @endif

        @if ($state === 'register')
            <form method="post" action="{{ route('invitation.accept', $token) }}">
                @csrf
                <p class="muted">Giriş adın: <b>{{ $invitation->email }}</b></p>
                <label for="name">Ad soyad</label>
                <input id="name" name="name" value="{{ old('name') }}" required autocomplete="name">
                @error('name')<div class="error">{{ $message }}</div>@enderror
                <label for="password">Şifre (en az 10 karakter)</label>
                <input id="password" type="password" name="password" required autocomplete="new-password">
                @error('password')<div class="error">{{ $message }}</div>@enderror
                <label for="password_confirmation">Şifre (tekrar)</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password">
                <button class="btn" type="submit">Hesabımı oluştur ve katıl</button>
                <p class="muted" style="margin-top: 12px;">Sonraki adımda telefonundaki doğrulama uygulamasıyla (Google Authenticator vb.) iki adımlı doğrulama kurman istenir.</p>
            </form>
        @elseif ($state === 'login')
            <p>Bu adresle zaten bir hesabın var ({{ $invitation->email }}). Giriş yap; sonra bu sayfaya dönüp daveti kabul edeceksin.</p>
            <a class="btn" href="{{ url('/admin/login') }}">Giriş yap</a>
        @elseif ($state === 'accept')
            <form method="post" action="{{ route('invitation.accept', $token) }}">
                @csrf
                <button class="btn" type="submit">Daveti kabul et</button>
            </form>
        @else
            <p>Bu davet <b>{{ $invitation->email }}</b> adresine gönderildi, ama şu an başka bir hesapla giriş yapmışsın. Çıkış yapıp o adresle giriş yap.</p>
        @endif
    @endif
</div>
</body>
</html>
