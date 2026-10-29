<?php

declare(strict_types=1);

use App\Filament\Resources\Audit\AuditResource;
use App\Filament\Resources\Audit\Pages\ListAuditLogs;
use App\Filament\Resources\Users\UserResource;
use App\Http\Middleware\EnforceTwoFactorForPrivilegedRoles;
use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Iam\Application\ActivateUser;
use App\Modules\Iam\Application\DeactivateUser;
use App\Modules\Iam\Application\ResetUserPassword;
use App\Modules\Iam\Domain\IamException;
use App\Modules\Iam\Domain\RoleName;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\PanelUsers;

beforeEach(function () {
    PanelUsers::seedRoles();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

function iamAna(array $attributes = [], RoleName $role = RoleName::Almacenero): User
{
    return PanelUsers::withRole($role, $attributes + [
        'username' => 'ana', 'email' => 'ana@cityofertas.test', 'password' => 'caballo-azul-lluvia-77',
    ]);
}

function iamLogin(string $email, string $password): Testable
{
    return Livewire::test(Login::class)
        ->set('data.email', $email)
        ->set('data.password', $password)
        ->call('authenticate');
}

// HU-002 Iniciar sesión

it('CA-002-1 acceso autorizado: ana@cityofertas.test con clave válida entra y actualiza last_login_at', function () {
    $ana = iamAna();

    iamLogin('ana@cityofertas.test', 'caballo-azul-lluvia-77')->assertHasNoFormErrors()->assertRedirect();

    expect(auth()->id())->toBe($ana->id);
    expect($ana->refresh()->last_login_at)->not->toBeNull();
});

it('CA-002-2 cuenta restringida: inactiva, bloqueada o sin rol no entra aunque la clave sea correcta', function () {
    $inactive = iamAna(['is_active' => false]);
    $locked = PanelUsers::withRole(RoleName::Almacenero, ['email' => 'luis@cityofertas.test', 'password' => 'caballo-azul-lluvia-77']);
    $locked->forceFill(['locked_until' => now()->addMinutes(15)])->save();
    $lockedUntil = $locked->refresh()->locked_until;
    User::factory()->create(['email' => 'sinrol@cityofertas.test', 'password' => 'caballo-azul-lluvia-77']);

    foreach (['ana@cityofertas.test', 'luis@cityofertas.test', 'sinrol@cityofertas.test'] as $email) {
        iamLogin($email, 'caballo-azul-lluvia-77')->assertHasFormErrors(['email']);
        expect(auth()->check())->toBeFalse();
    }

    expect($inactive->refresh()->getAllPermissions())->not->toBeEmpty(); // no se tocaron permisos
    expect($locked->refresh()->locked_until?->equalTo($lockedUntil))->toBeTrue(); // el bloqueo no se levanta
    expect($locked->last_login_at)->toBeNull();
});

it('CA-002-3 credenciales incorrectas: mensaje genérico y se contabiliza el fallo', function () {
    $ana = iamAna();

    $wrongPassword = iamLogin('ana@cityofertas.test', 'clave-equivocada-123')->assertHasFormErrors(['email']);
    $unknownEmail = iamLogin('nadie@cityofertas.test', 'clave-equivocada-123')->assertHasFormErrors(['email']);

    // El mismo mensaje para correo existente y desconocido: no revela qué dato falló.
    expect($wrongPassword->errors()->first('data.email'))->toBe($unknownEmail->errors()->first('data.email'));
    expect(auth()->check())->toBeFalse();
    expect((int) DB::table('users')->where('id', $ana->id)->value('failed_attempts'))->toBe(1);
});

// HU-004 Desactivar y reactivar usuarios

it('CA-004-1 el administrador 1 desactiva al usuario 2: conserva cuenta e historial y audita', function () {
    $admin = PanelUsers::withRole(RoleName::Admin);
    $target = PanelUsers::withRole(RoleName::Almacenero);

    app(DeactivateUser::class)($target->id, $admin->id);

    expect(User::query()->find($target->id)?->is_active)->toBeFalse();
    expect($target->refresh()->hasRole('almacenero'))->toBeTrue();
    expect(DB::table('audit_logs')->where('action', 'user.deactivated')->where('entity_id', $target->id)->where('actor_id', $admin->id)->count())->toBe(1);
});

it('CA-004-2 no se desactiva a sí mismo ni al único administrador activo', function () {
    $admin = PanelUsers::withRole(RoleName::Admin);
    $supervisor = PanelUsers::withRole(RoleName::Supervisor);

    expect(fn () => app(DeactivateUser::class)($admin->id, $admin->id))->toThrow(IamException::class, 'propia cuenta');
    expect(fn () => app(DeactivateUser::class)($admin->id, $supervisor->id))->toThrow(IamException::class, 'último administrador');

    expect($admin->refresh()->is_active)->toBeTrue();
    expect(DB::table('audit_logs')->where('action', 'user.deactivated')->count())->toBe(0);
});

it('CA-004-3 reactivar dos veces deja la cuenta activa con una sola auditoría', function () {
    $admin = PanelUsers::withRole(RoleName::Admin);
    $target = PanelUsers::withRole(RoleName::Almacenero, ['is_active' => false]);

    app(ActivateUser::class)($target->id, $admin->id);
    app(ActivateUser::class)($target->id, $admin->id);

    expect($target->refresh()->is_active)->toBeTrue();
    expect(DB::table('audit_logs')->where('action', 'user.activated')->where('entity_id', $target->id)->count())->toBe(1);
});

// HU-005 Restablecer contraseña

it('CA-005-1 restablecer con otra-clave-larga-segura-9 guarda hash, limpia bloqueo e intentos y audita sin secretos', function () {
    $admin = PanelUsers::withRole(RoleName::Admin);
    $target = PanelUsers::withRole(RoleName::Almacenero);
    $target->forceFill(['failed_attempts' => 3, 'locked_until' => now()->addMinutes(15)])->save();

    app(ResetUserPassword::class)($target->id, 'otra-clave-larga-segura-9', $admin->id);

    $target->refresh();
    expect(Hash::check('otra-clave-larga-segura-9', $target->password))->toBeTrue();
    expect($target->locked_until)->toBeNull();
    expect((int) DB::table('users')->where('id', $target->id)->value('failed_attempts'))->toBe(0);
    $audit = DB::table('audit_logs')->where('action', 'user.password_reset')->where('entity_id', $target->id)->first();
    expect($audit)->not->toBeNull();
    expect((string) $audit->new_values.(string) $audit->old_values)->not->toContain('otra-clave')->not->toContain('$2y$');
});

it('CA-005-2 clave 123 o usuario 999 se rechazan sin tocar credenciales ni auditar éxito', function () {
    $admin = PanelUsers::withRole(RoleName::Admin);
    $target = PanelUsers::withRole(RoleName::Almacenero);
    $hash = $target->password;

    expect(fn () => app(ResetUserPassword::class)($target->id, '123', $admin->id))->toThrow(IamException::class);
    expect(fn () => app(ResetUserPassword::class)(999, 'otra-clave-larga-segura-9', $admin->id))->toThrow(IamException::class, 'no existe');

    expect($target->refresh()->password)->toBe($hash);
    expect(DB::table('audit_logs')->where('action', 'user.password_reset')->count())->toBe(0);
});

it('CA-005-3 el rol lectura no puede restablecer contraseñas ajenas (users.manage)', function () {
    $reader = PanelUsers::withRole(RoleName::Lectura);
    $this->actingAs($reader);

    expect($reader->can('users.manage'))->toBeFalse();
    expect(UserResource::canViewAny())->toBeFalse();
    $this->get(UserResource::getUrl('index'))->assertForbidden();
});

// HU-006 Segundo factor

function iamEnableTotp(User $user): array
{
    $provider = AppAuthentication::make()->recoverable();
    $secret = $provider->generateSecret();
    $codes = $provider->generateRecoveryCodes();
    $provider->saveSecret($user, $secret);
    $provider->saveRecoveryCodes($user, $codes);

    return [$provider, $secret, $codes];
}

it('CA-006-1 con TOTP y códigos de recuperación activos, el acceso exige y verifica el código vigente', function () {
    $admin = iamAna([], RoleName::Admin);
    [$provider, $secret] = iamEnableTotp($admin);

    $login = iamLogin('ana@cityofertas.test', 'caballo-azul-lluvia-77');
    expect(auth()->check())->toBeFalse(); // solo con la clave no basta
    expect($login->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();

    $login->set('data.multiFactor.app.code', $provider->getCurrentCode($admin->refresh(), $secret))
        ->call('authenticate')
        ->assertHasNoErrors();

    expect(auth()->id())->toBe($admin->id);
});

it('CA-006-2 con require_2fa=true, admin o supervisor sin secreto solo navegan al perfil', function () {
    config(['security.require_2fa' => true]);
    $run = static function (string $uri, User $user): Response {
        $request = Request::create($uri);
        $request->setUserResolver(static fn () => $user);

        return (new EnforceTwoFactorForPrivilegedRoles)->handle($request, static fn (): Response => new Response('ok'));
    };

    foreach ([RoleName::Admin, RoleName::Supervisor] as $role) {
        $user = PanelUsers::withRole($role);
        expect($run('/admin/products', $user)->isRedirect())->toBeTrue();
        expect($run('/admin/profile', $user)->getContent())->toBe('ok');
    }
});

it('CA-006-3 un TOTP erróneo no entra y el código de recuperación funciona una sola vez', function () {
    $admin = iamAna([], RoleName::Admin);
    [, , $codes] = iamEnableTotp($admin);

    $login = iamLogin('ana@cityofertas.test', 'caballo-azul-lluvia-77');
    $login->set('data.multiFactor.app.code', '000000')->call('authenticate')->assertHasErrors();
    expect(auth()->check())->toBeFalse();

    $login->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.code', null)
        ->set('data.multiFactor.app.recoveryCode', $codes[0])
        ->call('authenticate')
        ->assertHasNoErrors();
    expect(auth()->id())->toBe($admin->id);

    auth()->logout();
    $again = iamLogin('ana@cityofertas.test', 'caballo-azul-lluvia-77');
    $again->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', $codes[0])
        ->call('authenticate')
        ->assertHasErrors();
    expect(auth()->check())->toBeFalse();
});

// HU-007 Consultar auditoría

it('CA-007-1 con audit.view, filtrar product.updated del actor 7 entre 01/10 y 28/10 muestra solo coincidencias', function () {
    $viewer = PanelUsers::withRole(RoleName::Admin);
    $actor = PanelUsers::withRole(RoleName::Supervisor, ['id' => 7]);
    $other = PanelUsers::withRole(RoleName::Supervisor);
    $row = static fn (int $actorId, string $action, string $date): int => (int) DB::table('audit_logs')->insertGetId([
        'actor_id' => $actorId, 'action' => $action, 'entity_type' => 'product', 'entity_id' => 1,
        'old_values' => json_encode(['name' => 'Cuaderno']), 'new_values' => json_encode(['name' => 'Cuaderno A4']),
        'created_at' => $date.' 10:00:00-05',
    ], 'id');
    $match = $row(7, 'product.updated', '2026-10-15');
    $wrongAction = $row(7, 'product.created', '2026-10-15');
    $wrongActor = $row($other->id, 'product.updated', '2026-10-15');
    $outOfRange = $row(7, 'product.updated', '2026-09-30');
    $this->actingAs($viewer);

    Livewire::test(ListAuditLogs::class)
        ->filterTable('action', 'product.updated')
        ->filterTable('actor_id', $actor->id)
        ->filterTable('period', ['from' => '2026-10-01', 'until' => '2026-10-28'])
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$wrongAction, $wrongActor, $outOfRange]);

    // Detalle legible del registro: antes y después.
    $detail = view('filament.audit-detail', ['log' => AuditLog::query()->findOrFail($match)])->render();
    expect($detail)->toContain('&quot;name&quot;: &quot;Cuaderno&quot;')->toContain('&quot;name&quot;: &quot;Cuaderno A4&quot;');
});

it('CA-007-2 el rol lectura no accede a la auditoría ni recibe sus datos', function () {
    DB::table('audit_logs')->insert(['action' => 'product.updated', 'entity_type' => 'product', 'new_values' => json_encode(['secreto' => 'x'])]);
    $this->actingAs(PanelUsers::withRole(RoleName::Lectura));

    expect(AuditResource::canViewAny())->toBeFalse();
    $this->get(AuditResource::getUrl('index'))->assertForbidden();
});

it('CA-007-3 UPDATE, DELETE y TRUNCATE sobre audit_logs son rechazados y el registro se conserva', function () {
    $id = (int) DB::table('audit_logs')->insertGetId(['action' => 'product.updated', 'entity_type' => 'product'], 'id');

    foreach ([
        'UPDATE' => fn () => DB::table('audit_logs')->where('id', $id)->update(['action' => 'x']),
        'DELETE' => fn () => DB::table('audit_logs')->where('id', $id)->delete(),
        'TRUNCATE' => fn () => DB::statement('TRUNCATE audit_logs'),
    ] as $operation => $mutation) {
        expect(fn () => DB::transaction($mutation))->toThrow(QueryException::class, 'inmutable');
    }

    expect(DB::table('audit_logs')->where('id', $id)->value('action'))->toBe('product.updated');
});
