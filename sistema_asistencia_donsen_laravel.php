<?php

/**
 * ============================================================================
 * SISTEMA DE CONTROL DE ASISTENCIA POR MEDIO DE CÓDIGOS QR - EMPRESA DONSEN
 * ============================================================================
 * Autor: Jose Sevilla | Tutor: Jesus Reina | IUTA - Caracas, 2026
 * Arquitectura: Laravel (PHP 8.1+) + PostgreSQL / MySQL (db_asistencia_donsen)
 * Patrón: Modelo-Vista-Controlador (MVC) con Programación Orientada a Objetos
 * ============================================================================
 */

declare(strict_types=1);

$laravelProjectFiles = [

    // =========================================================================
    // 1. MIGRACIÓN DE BASE DE DATOS RELACIONAL (db_asistencia_donsen - Tabla 13)
    // =========================================================================
    'database/migrations/2026_01_01_000001_create_donsen_asistencia_tables.php' => <<<'LARAVEL_FILE'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabla: users con campo 'rol'
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100);
                $table->string('email', 150)->unique();
                $table->string('password', 255);
                $table->enum('rol', ['Director General', 'Administradora'])->default('Administradora');
                $table->rememberToken();
                $table->timestamps();
            });
        } else {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'rol')) {
                    $table->enum('rol', ['Director General', 'Administradora'])
                          ->default('Administradora')
                          ->after('password');
                }
            });
        }

        // 2. Tabla: departamentos
        Schema::create('departamentos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_departamento', 80);
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });

        // 3. Tabla: horarios
        Schema::create('horarios', function (Blueprint $table) {
            $table->id();
            $table->string('turno', 50);
            $table->time('hora_entrada');
            $table->time('hora_salida');
            $table->integer('tolerancia_min')->default(15);
            $table->timestamps();
        });

        // 4. Tabla: empleados
        Schema::create('empleados', function (Blueprint $table) {
            $table->id();
            $table->string('cedula', 20)->unique();
            $table->string('nombres_apellidos', 120);
            $table->string('cargo', 80);
            $table->foreignId('departamento_id')
                  ->constrained('departamentos')
                  ->onUpdate('cascade')
                  ->onDelete('restrict');
            $table->foreignId('horario_id')
                  ->constrained('horarios')
                  ->onUpdate('cascade')
                  ->onDelete('restrict');
            $table->string('codigo_qr_empleado', 255)->unique();
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });

        // 5. Tabla: sede_qrs
        Schema::create('sede_qrs', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_sede', 100);
            $table->string('codigo_qr_fijo', 255)->unique();
            $table->string('ubicacion', 150);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // 6. Tabla: asistencias
        Schema::create('asistencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')
                  ->constrained('empleados')
                  ->onUpdate('cascade')
                  ->onDelete('cascade');
            $table->foreignId('sede_qr_id')
                  ->constrained('sede_qrs')
                  ->onUpdate('cascade')
                  ->onDelete('restrict');
            $table->date('fecha');
            $table->time('hora_entrada')->nullable();
            $table->time('hora_salida')->nullable();
            $table->enum('estatus', ['A tiempo', 'Retardo', 'Inasistencia'])->default('A tiempo');
            $table->timestamps();

            $table->unique(['empleado_id', 'fecha'], 'idx_empleado_fecha_unica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asistencias');
        Schema::dropIfExists('sede_qrs');
        Schema::dropIfExists('empleados');
        Schema::dropIfExists('horarios');
        Schema::dropIfExists('departamentos');
    }
};
LARAVEL_FILE,

    // =========================================================================
    // 1B. MIGRACIÓN ANTI-SUPLANTACIÓN: VINCULACIÓN DE DISPOSITIVO ÚNICO
    // =========================================================================
    'database/migrations/2026_01_02_000002_add_device_lock_to_empleados_table.php' => <<<'LARAVEL_FILE'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            if (!Schema::hasColumn('empleados', 'device_token')) {
                $table->string('device_token', 100)->nullable()->unique()->after('codigo_qr_empleado');
                $table->string('device_info', 120)->nullable()->after('device_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            $table->dropColumn(['device_token', 'device_info']);
        });
    }
};
LARAVEL_FILE,

    // =========================================================================
    // 1C. MIGRACIÓN GEOCERCA GPS POR SEDE Y AUDITORÍA DE MAPA EN ASISTENCIAS
    // =========================================================================
    'database/migrations/2026_01_03_000003_add_gps_geofence_to_sede_and_asistencias.php' => <<<'LARAVEL_FILE'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sede_qrs', function (Blueprint $table) {
            if (!Schema::hasColumn('sede_qrs', 'latitud')) {
                $table->decimal('latitud', 10, 7)->nullable()->after('ubicacion');
                $table->decimal('longitud', 10, 7)->nullable()->after('latitud');
                $table->integer('radio_metros')->default(120)->after('longitud');
            }
        });

        Schema::table('asistencias', function (Blueprint $table) {
            if (!Schema::hasColumn('asistencias', 'latitud_marcaje')) {
                $table->decimal('latitud_marcaje', 10, 7)->nullable()->after('estatus');
                $table->decimal('longitud_marcaje', 10, 7)->nullable()->after('latitud_marcaje');
                $table->integer('distancia_metros')->nullable()->after('longitud_marcaje');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sede_qrs', function (Blueprint $table) {
            $table->dropColumn(['latitud', 'longitud', 'radio_metros']);
        });

        Schema::table('asistencias', function (Blueprint $table) {
            $table->dropColumn(['latitud_marcaje', 'longitud_marcaje', 'distancia_metros']);
        });
    }
};
LARAVEL_FILE,

    // =========================================================================
    // 2. SEEDER CON LA PLANTILLA CENSAL DE 9 TRABAJADORES DE DONSEN
    // =========================================================================
    'database/seeders/DatabaseSeeder.php' => <<<'LARAVEL_FILE'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Departamento;
use App\Models\Horario;
use App\Models\SedeQr;
use App\Models\Empleado;
use App\Models\Asistencia;
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'director@donsen.com'],
            [
                'name' => 'Carlos Mendoza',
                'password' => Hash::make('password123'),
                'rol' => 'Director General',
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@donsen.com'],
            [
                'name' => 'María Fernanda Gómez',
                'password' => Hash::make('password123'),
                'rol' => 'Administradora',
            ]
        );

        $dir = Departamento::create([
            'nombre_departamento' => 'Dirección',
            'descripcion' => 'Dirección General y toma de decisiones estratégicas.',
        ]);
        $adm = Departamento::create([
            'nombre_departamento' => 'Administración',
            'descripcion' => 'Gestión administrativa, facturación, contabilidad y nómina.',
        ]);
        $alm = Departamento::create([
            'nombre_departamento' => 'Almacén',
            'descripcion' => 'Control de inventario, recepción y despacho de tuberías.',
        ]);
        $tra = Departamento::create([
            'nombre_departamento' => 'Transporte',
            'descripcion' => 'Logística y distribución vehicular de mercancía.',
        ]);

        $turnoAdmin = Horario::create([
            'turno' => 'Turno Administrativo',
            'hora_entrada' => '08:00:00',
            'hora_salida' => '17:00:00',
            'tolerancia_min' => 15,
        ]);

        $turnoAlmacen = Horario::create([
            'turno' => 'Turno Almacén y Logística',
            'hora_entrada' => '07:30:00',
            'hora_salida' => '16:30:00',
            'tolerancia_min' => 15,
        ]);

        $sede = SedeQr::create([
            'nombre_sede' => 'Sede Principal Caracas',
            'codigo_qr_fijo' => 'DONSEN-SEDE-CCS-2026',
            'ubicacion' => 'Entrada Principal - Área Administrativa y Almacén',
            'latitud' => 10.4806000,
            'longitud' => -66.9036000,
            'radio_metros' => 120,
            'activo' => true,
        ]);

        $plantilla = [
            ['V-15.420.110', 'Carlos Mendoza', 'Director General', $dir->id, $turnoAdmin->id, 'DONSEN-EMP-001-15420110'],
            ['V-19.834.512', 'María Fernanda Gómez', 'Administradora', $adm->id, $turnoAdmin->id, 'DONSEN-EMP-002-19834512'],
            ['V-21.309.874', 'Daniela Rodríguez', 'Administradora', $adm->id, $turnoAdmin->id, 'DONSEN-EMP-003-21309874'],
            ['V-17.652.390', 'Roberto Castillo', 'Jefe de Almacén', $alm->id, $turnoAlmacen->id, 'DONSEN-EMP-004-17652390'],
            ['V-18.910.445', 'Luis Alberto Pérez', 'Jefe de Almacén', $alm->id, $turnoAlmacen->id, 'DONSEN-EMP-005-18910445'],
            ['V-24.512.889', 'Andrés Felipe Morales', 'Almacenista', $alm->id, $turnoAlmacen->id, 'DONSEN-EMP-006-24512889'],
            ['V-26.789.123', 'Keiner José Vargas', 'Almacenista', $alm->id, $turnoAlmacen->id, 'DONSEN-EMP-007-26789123'],
            ['V-28.104.567', 'Brayan Alexander Torres', 'Almacenista', $alm->id, $turnoAlmacen->id, 'DONSEN-EMP-008-28104567'],
            ['V-16.298.741', 'Héctor Ramón Suárez', 'Chofer', $tra->id, $turnoAlmacen->id, 'DONSEN-EMP-009-16298741'],
        ];

        $empleadosCreados = [];
        foreach ($plantilla as $p) {
            $empleadosCreados[] = Empleado::create([
                'cedula' => $p[0],
                'nombres_apellidos' => $p[1],
                'cargo' => $p[2],
                'departamento_id' => $p[3],
                'horario_id' => $p[4],
                'codigo_qr_empleado' => $p[5],
                'estado' => true,
            ]);
        }

        $hoy = Carbon::today('America/Caracas')->toDateString();
        $muestrasHoy = [
            [$empleadosCreados[0]->id, '07:55:00', null, 'A tiempo'],
            [$empleadosCreados[1]->id, '08:02:00', null, 'A tiempo'],
            [$empleadosCreados[2]->id, '08:22:00', null, 'Retardo'],
            [$empleadosCreados[3]->id, '07:24:00', null, 'A tiempo'],
            [$empleadosCreados[4]->id, '07:31:00', null, 'A tiempo'],
            [$empleadosCreados[8]->id, '07:52:00', null, 'Retardo'],
        ];

        foreach ($muestrasHoy as $m) {
            Asistencia::create([
                'empleado_id' => $m[0],
                'sede_qr_id' => $sede->id,
                'fecha' => $hoy,
                'hora_entrada' => $m[1],
                'hora_salida' => $m[2],
                'estatus' => $m[3],
            ]);
        }
    }
}
LARAVEL_FILE,

    // =========================================================================
    // 3. MODELOS ELOQUENT POO (DIAGRAMA DE CLASES - FIGURA 3 DEL TEG)
    // =========================================================================
    'app/Models/User.php' => <<<'LARAVEL_FILE'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'rol',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function esDirector(): bool
    {
        return $this->rol === 'Director General';
    }
}
LARAVEL_FILE,

    'app/Models/Departamento.php' => <<<'LARAVEL_FILE'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Departamento extends Model
{
    protected $table = 'departamentos';

    protected $fillable = [
        'nombre_departamento',
        'descripcion',
    ];

    public function empleados(): HasMany
    {
        return $this->hasMany(Empleado::class, 'departamento_id');
    }
}
LARAVEL_FILE,

    'app/Models/Horario.php' => <<<'LARAVEL_FILE'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class Horario extends Model
{
    protected $table = 'horarios';

    protected $fillable = [
        'turno',
        'hora_entrada',
        'hora_salida',
        'tolerancia_min',
    ];

    public function empleados(): HasMany
    {
        return $this->hasMany(Empleado::class, 'horario_id');
    }

    public function calcularEstatusPuntualidad(Carbon $horaMarcaje): string
    {
        $entradaOficial = Carbon::parse($horaMarcaje->toDateString() . ' ' . $this->hora_entrada, 'America/Caracas');
        $limiteTolerancia = $entradaOficial->copy()->addMinutes((int) $this->tolerancia_min);

        return $horaMarcaje->gt($limiteTolerancia) ? 'Retardo' : 'A tiempo';
    }
}
LARAVEL_FILE,

    'app/Models/SedeQr.php' => <<<'LARAVEL_FILE'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SedeQr extends Model
{
    protected $table = 'sede_qrs';

    protected $fillable = [
        'nombre_sede',
        'codigo_qr_fijo',
        'ubicacion',
        'latitud',
        'longitud',
        'radio_metros',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'latitud' => 'float',
        'longitud' => 'float',
        'radio_metros' => 'integer',
    ];

    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class, 'sede_qr_id');
    }

    public static function validarCodigo(string $codigo): ?self
    {
        return self::where('codigo_qr_fijo', strtoupper(trim($codigo)))
                   ->where('activo', true)
                   ->first();
    }

    /**
     * Fórmula de Haversine: calcula la distancia exacta en metros entre
     * las coordenadas físicas de la sede Donsen y el teléfono del trabajador.
     */
    public function calcularDistanciaMetros(float $latUsuario, float $lngUsuario): int
    {
        if ($this->latitud === null || $this->longitud === null) {
            return 0;
        }

        $radioTierra = 6371000; // metros
        $dLat = deg2rad($latUsuario - $this->latitud);
        $dLng = deg2rad($lngUsuario - $this->longitud);

        $a = sin($dLat / 2) * sin($dLat / 2)
           + cos(deg2rad($this->latitud)) * cos(deg2rad($latUsuario))
           * sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return (int) round($radioTierra * $c);
    }
}
LARAVEL_FILE,

    'app/Models/Empleado.php' => <<<'LARAVEL_FILE'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Empleado extends Model
{
    protected $table = 'empleados';

    protected $fillable = [
        'cedula',
        'nombres_apellidos',
        'cargo',
        'departamento_id',
        'horario_id',
        'codigo_qr_empleado',
        'device_token',
        'device_info',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_id');
    }

    public function horario(): BelongsTo
    {
        return $this->belongsTo(Horario::class, 'horario_id');
    }

    public function asistencias(): HasMany
    {
        return $this->hasMany(Asistencia::class, 'empleado_id');
    }

    public static function generarTokenQR(int $correlativo, string $cedula): string
    {
        $soloDigitos = preg_replace('/\D/', '', $cedula) ?: (string) (10000000 + $correlativo);
        return sprintf('DONSEN-EMP-%03d-%s', $correlativo, $soloDigitos);
    }
}
LARAVEL_FILE,

    'app/Models/Asistencia.php' => <<<'LARAVEL_FILE'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Asistencia extends Model
{
    protected $table = 'asistencias';

    protected $fillable = [
        'empleado_id',
        'sede_qr_id',
        'fecha',
        'hora_entrada',
        'hora_salida',
        'estatus',
        'latitud_marcaje',
        'longitud_marcaje',
        'distancia_metros',
    ];

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function sedeQr(): BelongsTo
    {
        return $this->belongsTo(SedeQr::class, 'sede_qr_id');
    }

    public function getHorasLaboradasAttribute(): string
    {
        if (!$this->hora_entrada) {
            return '0h 00m';
        }
        if (!$this->hora_salida) {
            return 'En jornada';
        }

        $inicio = Carbon::parse($this->fecha . ' ' . $this->hora_entrada);
        $fin = Carbon::parse($this->fecha . ' ' . $this->hora_salida);
        $minutos = max(0, $inicio->diffInMinutes($fin, false));

        return sprintf('%dh %02dm', intdiv($minutos, 60), $minutos % 60);
    }
}
LARAVEL_FILE,

    // =========================================================================
    // 4. CONTROLADORES MVC (AUTH, DASHBOARD, EMPLEADOS, ASISTENCIA QR, REPORTES)
    // =========================================================================
    'app/Http/Controllers/AuthController.php' => <<<'LARAVEL_FILE'
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credenciales = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::attempt($credenciales, $request->boolean('remember'))) {
            $request->session()->regenerate();
            return redirect()->intended(route('dashboard'))
                             ->with('success', 'Bienvenido(a), ' . Auth::user()->name);
        }

        return back()->withErrors([
            'email' => 'Las credenciales ingresadas no coinciden con nuestros registros.',
        ])->onlyInput('email');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Sesión cerrada correctamente.');
    }
}
LARAVEL_FILE,

    'app/Http/Controllers/DashboardController.php' => <<<'LARAVEL_FILE'
<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\Asistencia;
use App\Models\Departamento;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $hoy = Carbon::today('America/Caracas')->toDateString();

        $empleadosActivos = Empleado::with(['departamento', 'horario'])
            ->where('estado', true)
            ->orderBy('id')
            ->get();

        $asistenciasHoy = Asistencia::where('fecha', $hoy)
            ->get()
            ->keyBy('empleado_id');

        $totalEmpleados = $empleadosActivos->count();
        $presentesHoy = $asistenciasHoy->whereNotNull('hora_entrada')->count();
        $retardosHoy = $asistenciasHoy->where('estatus', 'Retardo')->count();
        $ausentesHoy = max(0, $totalEmpleados - $presentesHoy);
        $porcentajeAsistencia = $totalEmpleados > 0
            ? round(($presentesHoy / $totalEmpleados) * 100)
            : 0;

        $departamentos = Departamento::withCount('empleados')->get();

        return view('dashboard', compact(
            'hoy',
            'empleadosActivos',
            'asistenciasHoy',
            'totalEmpleados',
            'presentesHoy',
            'retardosHoy',
            'ausentesHoy',
            'porcentajeAsistencia',
            'departamentos'
        ));
    }
}
LARAVEL_FILE,

    'app/Http/Controllers/EmpleadoController.php' => <<<'LARAVEL_FILE'
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Empleado;
use App\Models\Departamento;
use App\Models\Horario;
use App\Models\SedeQr;

class EmpleadoController extends Controller
{
    public function index(Request $request)
    {
        $query = Empleado::with(['departamento', 'horario']);

        if ($request->filled('buscar')) {
            $termino = '%' . $request->buscar . '%';
            $query->where(function ($q) use ($termino) {
                $q->where('nombres_apellidos', 'like', $termino)
                  ->orWhere('cedula', 'like', $termino)
                  ->orWhere('cargo', 'like', $termino);
            });
        }

        if ($request->filled('departamento_id')) {
            $query->where('departamento_id', $request->departamento_id);
        }

        $empleados = $query->orderBy('id')->get();
        $departamentos = Departamento::all();
        $horarios = Horario::all();
        $sedeQr = SedeQr::where('activo', true)->first();

        return view('empleados.index', compact('empleados', 'departamentos', 'horarios', 'sedeQr'));
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'cedula' => ['required', 'string', 'max:20', 'unique:empleados,cedula'],
            'nombres_apellidos' => ['required', 'string', 'max:120'],
            'cargo' => ['required', 'string', 'max:80'],
            'departamento_id' => ['required', 'exists:departamentos,id'],
            'horario_id' => ['required', 'exists:horarios,id'],
            'estado' => ['required', 'boolean'],
        ]);

        $siguienteId = (Empleado::max('id') ?? 0) + 1;
        $datos['cedula'] = strtoupper(trim($datos['cedula']));
        $datos['codigo_qr_empleado'] = Empleado::generarTokenQR($siguienteId, $datos['cedula']);

        Empleado::create($datos);

        return redirect()->route('empleados.index')
                         ->with('success', 'Empleado registrado y credencial QR generada exitosamente.');
    }

    public function update(Request $request, Empleado $empleado)
    {
        $datos = $request->validate([
            'cedula' => ['required', 'string', 'max:20', 'unique:empleados,cedula,' . $empleado->id],
            'nombres_apellidos' => ['required', 'string', 'max:120'],
            'cargo' => ['required', 'string', 'max:80'],
            'departamento_id' => ['required', 'exists:departamentos,id'],
            'horario_id' => ['required', 'exists:horarios,id'],
            'estado' => ['required', 'boolean'],
        ]);

        $datos['cedula'] = strtoupper(trim($datos['cedula']));
        $empleado->update($datos);

        return redirect()->route('empleados.index')
                         ->with('success', 'Ficha del trabajador actualizada correctamente.');
    }

    public function toggleEstado(Empleado $empleado)
    {
        $empleado->update(['estado' => !$empleado->estado]);

        return redirect()->route('empleados.index')
                         ->with('success', 'Estado laboral actualizado para ' . $empleado->nombres_apellidos);
    }

    public function destroy(Empleado $empleado)
    {
        $nombre = $empleado->nombres_apellidos;
        $empleado->delete();

        return redirect()->route('empleados.index')
                         ->with('success', 'Empleado eliminado del sistema: ' . $nombre);
    }

    public function resetDevice(Empleado $empleado)
    {
        $empleado->update([
            'device_token' => null,
            'device_info' => null,
        ]);

        return redirect()->route('empleados.index')
                         ->with('success', 'Teléfono desvinculado para ' . $empleado->nombres_apellidos . '. Podrá registrar un nuevo equipo en su próximo escaneo.');
    }

    public function updateSedeUbicacion(Request $request, SedeQr $sede)
    {
        $datos = $request->validate([
            'nombre_sede' => ['required', 'string', 'max:100'],
            'ubicacion' => ['required', 'string', 'max:150'],
            'latitud' => ['required', 'numeric', 'between:-90,90'],
            'longitud' => ['required', 'numeric', 'between:-180,180'],
            'radio_metros' => ['required', 'integer', 'min:20', 'max:5000'],
        ]);

        $sede->update($datos);

        return redirect()->route('empleados.index')
                         ->with('success', 'Coordenadas GPS y radio de geocerca actualizados para ' . $sede->nombre_sede . '.');
    }
}
LARAVEL_FILE,

    'app/Http/Controllers/AsistenciaController.php' => <<<'LARAVEL_FILE'
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Empleado;
use App\Models\Asistencia;
use App\Models\SedeQr;
use Carbon\Carbon;

class AsistenciaController extends Controller
{
    public function vistaEscaner(Request $request)
    {
        $hoy = Carbon::today('America/Caracas')->toDateString();
        $empleados = Empleado::with('horario')
            ->where('estado', true)
            ->orderBy('nombres_apellidos')
            ->get();

        $asistenciasHoy = Asistencia::where('fecha', $hoy)->get()->keyBy('empleado_id');
        $sedeQr = SedeQr::where('activo', true)->first();

        // Si el trabajador escanea el QR de la entrada con la cámara nativa del teléfono
        $sedePrevalidada = null;
        if ($request->filled('sede')) {
            $sedePrevalidada = SedeQr::validarCodigo($request->query('sede'));
        }

        return view('scanner.index', compact('empleados', 'asistenciasHoy', 'sedeQr', 'hoy', 'sedePrevalidada'));
    }

    public function registrarMarcaje(Request $request)
    {
        $request->validate([
            'qr_sede' => ['required', 'string'],
            'identificador' => ['required', 'string'],
            'device_token' => ['required', 'string', 'min:10', 'max:100'],
            'device_info' => ['nullable', 'string', 'max:120'],
            'latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180'],
            'hora_simulada' => ['nullable', 'date_format:H:i'],
        ]);

        // Extraer código de sede (soporta tanto código plano como URL completa del QR de entrada)
        $rawSede = trim($request->qr_sede);
        if (str_contains($rawSede, 'sede=')) {
            parse_str(parse_url($rawSede, PHP_URL_QUERY) ?? '', $queryParams);
            $rawSede = $queryParams['sede'] ?? $rawSede;
        }

        $sede = SedeQr::validarCodigo($rawSede);
        if (!$sede) {
            return response()->json([
                'ok' => false,
                'tipo' => 'error',
                'titulo' => 'QR de Sede Inválido',
                'mensaje' => 'El código de sede recibido (' . $rawSede . ') no corresponde al QR oficial de la entrada de Donsen. Presione "Cambiar QR" y escanee el cartel de la entrada.',
            ], 400);
        }

        $esAdminEnPruebas = auth()->check() && $request->boolean('modo_prueba_admin');

        // =====================================================================
        // VALIDACIÓN DE GEOCERCA GPS EN MAPA (EVITA QUE SE PASEN EL QR POR FOTO)
        // =====================================================================
        $latitud = $request->filled('latitud') ? (float) $request->latitud : null;
        $longitud = $request->filled('longitud') ? (float) $request->longitud : null;
        $distanciaMetros = null;

        if (!$esAdminEnPruebas && $sede->latitud !== null && $sede->longitud !== null) {
            if ($latitud === null || $longitud === null) {
                return response()->json([
                    'ok' => false,
                    'tipo' => 'error',
                    'badge' => 'UBICACIÓN GPS REQUERIDA',
                    'mensaje' => 'Debe activar el GPS y permitir el acceso a su ubicación en el navegador para verificar que se encuentra físicamente en la sede de Donsen.',
                ], 422);
            }

            $distanciaMetros = $sede->calcularDistanciaMetros($latitud, $longitud);

            if ($distanciaMetros > $sede->radio_metros) {
                return response()->json([
                    'ok' => false,
                    'tipo' => 'error',
                    'badge' => 'FUERA DEL RANGO DE LA SEDE (GPS)',
                    'mensaje' => 'Ubicación rechazada: se encuentra a ' . number_format($distanciaMetros, 0, ',', '.') . ' metros de ' . $sede->nombre_sede . ' (radio máximo permitido: ' . $sede->radio_metros . ' m). No se permite marcar asistencia fuera de la empresa.',
                ], 403);
            }
        } elseif ($latitud !== null && $longitud !== null && $sede->latitud !== null) {
            $distanciaMetros = $sede->calcularDistanciaMetros($latitud, $longitud);
        }

        // Buscar empleado por Token QR individual O por Cédula de Identidad
        $idInput = strtoupper(trim($request->identificador));
        $soloDigitos = preg_replace('/\D/', '', $idInput);

        $empleado = Empleado::with(['horario', 'departamento'])
            ->where(function ($q) use ($idInput, $soloDigitos) {
                $q->where('codigo_qr_empleado', $idInput)
                  ->orWhere('cedula', $idInput);
                if ($soloDigitos !== '') {
                    $q->orWhereRaw("REPLACE(REPLACE(UPPER(cedula), 'V-', ''), '.', '') = ?", [$soloDigitos]);
                }
            })
            ->first();

        if (!$empleado) {
            return response()->json([
                'ok' => false,
                'tipo' => 'error',
                'titulo' => 'Trabajador No Reconocido',
                'mensaje' => 'La cédula o código QR ingresado no corresponde a ningún empleado registrado.',
            ], 404);
        }

        if (!$empleado->estado) {
            return response()->json([
                'ok' => false,
                'tipo' => 'error',
                'titulo' => 'Trabajador Inactivo',
                'mensaje' => 'Su ficha se encuentra inactiva. Comuníquese con Administración.',
            ], 403);
        }

        // =====================================================================
        // VALIDACIÓN ANTI-SUPLANTACIÓN (1 TELÉFONO = 1 TRABAJADOR)
        // =====================================================================
        $deviceToken = trim($request->device_token);

        if (!$esAdminEnPruebas) {
            $duenoDispositivo = Empleado::where('device_token', $deviceToken)
                ->where('id', '!=', $empleado->id)
                ->first();

            if ($duenoDispositivo) {
                return response()->json([
                    'ok' => false,
                    'tipo' => 'error',
                    'badge' => 'SUPLANTACIÓN BLOQUEADA',
                    'empleado' => $empleado->nombres_apellidos,
                    'cargo' => $empleado->cargo,
                    'mensaje' => 'Este dispositivo móvil ya está vinculado exclusivamente a ' . $duenoDispositivo->nombres_apellidos . '. No está permitido marcar asistencia por otros compañeros.',
                ], 403);
            }

            if ($empleado->device_token && $empleado->device_token !== $deviceToken) {
                return response()->json([
                    'ok' => false,
                    'tipo' => 'error',
                    'badge' => 'DISPOSITIVO NO AUTORIZADO',
                    'empleado' => $empleado->nombres_apellidos,
                    'cargo' => $empleado->cargo,
                    'mensaje' => 'Usted ya tiene vinculado otro teléfono personal (' . ($empleado->device_info ?? 'Móvil registrado') . '). Si cambió de equipo, solicite a Administración desvincular el anterior.',
                ], 403);
            }

            if (!$empleado->device_token) {
                $empleado->update([
                    'device_token' => $deviceToken,
                    'device_info' => substr($request->input('device_info', 'Smartphone Personal'), 0, 120),
                ]);
            }
        }

        $hoy = Carbon::today('America/Caracas')->toDateString();
        $horaActual = $request->filled('hora_simulada')
            ? Carbon::parse($hoy . ' ' . $request->hora_simulada . ':00', 'America/Caracas')
            : Carbon::now('America/Caracas');

        $registro = Asistencia::where('empleado_id', $empleado->id)
            ->where('fecha', $hoy)
            ->first();

        // ESCENARIO A: Registro de ENTRADA
        if (!$registro || !$registro->hora_entrada) {
            $estatus = $empleado->horario->calcularEstatusPuntualidad($horaActual);

            Asistencia::updateOrCreate(
                ['empleado_id' => $empleado->id, 'fecha' => $hoy],
                [
                    'sede_qr_id' => $sede->id,
                    'hora_entrada' => $horaActual->format('H:i:s'),
                    'estatus' => $estatus,
                    'latitud_marcaje' => $latitud,
                    'longitud_marcaje' => $longitud,
                    'distancia_metros' => $distanciaMetros,
                ]
            );

            $textoGps = $distanciaMetros !== null ? " • Ubicación GPS verificada ({$distanciaMetros}m de la sede)." : '.';

            return response()->json([
                'ok' => true,
                'tipo' => $estatus === 'Retardo' ? 'warning' : 'success',
                'badge' => 'ENTRADA REGISTRADA',
                'empleado' => $empleado->nombres_apellidos,
                'cargo' => $empleado->cargo . ' • ' . $empleado->departamento->nombre_departamento,
                'hora' => $horaActual->format('H:i'),
                'estatus' => $estatus,
                'mapa_url' => ($latitud && $longitud) ? "https://www.google.com/maps?q={$latitud},{$longitud}" : null,
                'mensaje' => ($estatus === 'Retardo'
                    ? 'Entrada registrada fuera del margen de tolerancia (' . $empleado->horario->tolerancia_min . ' min)'
                    : 'Entrada registrada puntualmente') . $textoGps,
            ]);
        }

        // ESCENARIO B: Registro de SALIDA
        if ($registro->hora_entrada && !$registro->hora_salida) {
            if (!$request->filled('hora_simulada') && $registro->created_at && $registro->created_at->diffInSeconds(Carbon::now()) < 60) {
                return response()->json([
                    'ok' => false,
                    'tipo' => 'blocked',
                    'badge' => 'MARCAJE DUPLICADO BLOQUEADO',
                    'empleado' => $empleado->nombres_apellidos,
                    'cargo' => $empleado->cargo,
                    'hora' => substr($registro->hora_entrada, 0, 5),
                    'estatus' => $registro->estatus,
                    'mensaje' => 'Ya registró su entrada recientemente. Espere al menos 1 minuto para marcar salida.',
                ], 422);
            }

            $registro->update([
                'hora_salida' => $horaActual->format('H:i:s'),
            ]);

            return response()->json([
                'ok' => true,
                'tipo' => 'success',
                'badge' => 'SALIDA REGISTRADA',
                'empleado' => $empleado->nombres_apellidos,
                'cargo' => $empleado->cargo . ' • ' . $empleado->departamento->nombre_departamento,
                'hora' => $horaActual->format('H:i'),
                'estatus' => $registro->estatus,
                'mapa_url' => ($latitud && $longitud) ? "https://www.google.com/maps?q={$latitud},{$longitud}" : null,
                'mensaje' => 'Salida registrada. Total laborado hoy: ' . $registro->horas_laboradas,
            ]);
        }

        // ESCENARIO C: Jornada ya cerrada
        return response()->json([
            'ok' => false,
            'tipo' => 'blocked',
            'badge' => 'JORNADA YA COMPLETADA HOY',
            'empleado' => $empleado->nombres_apellidos,
            'cargo' => $empleado->cargo,
            'hora' => substr($registro->hora_entrada, 0, 5) . ' - ' . substr($registro->hora_salida, 0, 5),
            'estatus' => $registro->estatus,
            'mensaje' => 'Usted ya registró su entrada y salida en la jornada de hoy.',
        ], 422);
    }
}
LARAVEL_FILE,

    'app/Http/Controllers/ReporteController.php' => <<<'LARAVEL_FILE'
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Asistencia;
use App\Models\Departamento;
use App\Models\Empleado;
use Carbon\Carbon;

class ReporteController extends Controller
{
    private function construirConsultaFiltrada(Request $request)
    {
        $desde = $request->input('desde', Carbon::today('America/Caracas')->subDays(7)->toDateString());
        $hasta = $request->input('hasta', Carbon::today('America/Caracas')->toDateString());

        $query = Asistencia::with(['empleado.departamento', 'sedeQr'])
            ->whereBetween('fecha', [$desde, $hasta]);

        if ($request->filled('departamento_id')) {
            $deptId = $request->departamento_id;
            $query->whereHas('empleado', fn ($q) => $q->where('departamento_id', $deptId));
        }

        if ($request->filled('empleado_id')) {
            $query->where('empleado_id', $request->empleado_id);
        }

        if ($request->filled('estatus')) {
            $query->where('estatus', $request->estatus);
        }

        return [$query->orderByDesc('fecha')->orderByDesc('id')->get(), $desde, $hasta];
    }

    public function index(Request $request)
    {
        [$registros, $desde, $hasta] = $this->construirConsultaFiltrada($request);
        $departamentos = Departamento::all();
        $empleados = Empleado::orderBy('nombres_apellidos')->get();

        return view('reportes.index', compact(
            'registros',
            'departamentos',
            'empleados',
            'desde',
            'hasta'
        ));
    }

    public function exportarCsv(Request $request)
    {
        [$registros, $desde, $hasta] = $this->construirConsultaFiltrada($request);
        $nombreArchivo = 'Reporte_Asistencia_Donsen_' . $desde . '_al_' . $hasta . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$nombreArchivo\"",
        ];

        $callback = function () use ($registros) {
            $salida = fopen('php://output', 'w');
            fprintf($salida, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($salida, [
                'Fecha',
                'Cedula',
                'Empleado',
                'Cargo',
                'Departamento',
                'Hora Entrada',
                'Hora Salida',
                'Horas Laboradas',
                'Estatus',
            ], ';');

            foreach ($registros as $r) {
                fputcsv($salida, [
                    Carbon::parse($r->fecha)->format('d/m/Y'),
                    $r->empleado->cedula,
                    $r->empleado->nombres_apellidos,
                    $r->empleado->cargo,
                    $r->empleado->departamento->nombre_departamento,
                    $r->hora_entrada ? substr($r->hora_entrada, 0, 5) : 'N/A',
                    $r->hora_salida ? substr($r->hora_salida, 0, 5) : 'Pendiente',
                    $r->horas_laboradas,
                    $r->estatus,
                ], ';');
            }

            fclose($salida);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function vistaPdf(Request $request)
    {
        [$registros, $desde, $hasta] = $this->construirConsultaFiltrada($request);
        return view('reportes.pdf', compact('registros', 'desde', 'hasta'));
    }
}
LARAVEL_FILE,

    // =========================================================================
    // 4B. SERVICE PROVIDER (ZONA HORARIA VENEZUELA America/Caracas + HTTPS)
    // =========================================================================
    'app/Providers/AppServiceProvider.php' => <<<'LARAVEL_FILE'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        config(['app.timezone' => 'America/Caracas']);
        date_default_timezone_set('America/Caracas');
        Carbon::setLocale('es');

        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }
    }
}
LARAVEL_FILE,

    // =========================================================================
    // 5. ARCHIVO DE RUTAS WEB (routes/web.php)
    // =========================================================================
    'routes/web.php' => <<<'LARAVEL_FILE'
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\AsistenciaController;
use App\Http\Controllers\ReporteController;

// Rutas Públicas: Login y Terminal Móvil de Escaneo QR para Empleados
Route::get('/', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/marcaje-qr', [AsistenciaController::class, 'vistaEscaner'])->name('scanner.index');
Route::post('/api/marcar-asistencia', [AsistenciaController::class, 'registrarMarcaje'])->name('api.marcar');

// Rutas Administrativas Protegidas por Autenticación Laravel
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // CRUD Empleados, Horarios y Códigos QR
    Route::get('/empleados', [EmpleadoController::class, 'index'])->name('empleados.index');
    Route::post('/empleados', [EmpleadoController::class, 'store'])->name('empleados.store');
    Route::put('/empleados/{empleado}', [EmpleadoController::class, 'update'])->name('empleados.update');
    Route::patch('/empleados/{empleado}/estado', [EmpleadoController::class, 'toggleEstado'])->name('empleados.toggle');
    Route::patch('/empleados/{empleado}/reset-device', [EmpleadoController::class, 'resetDevice'])->name('empleados.resetDevice');
    Route::delete('/empleados/{empleado}', [EmpleadoController::class, 'destroy'])->name('empleados.destroy');
    Route::put('/sede/{sede}/ubicacion', [EmpleadoController::class, 'updateSedeUbicacion'])->name('sede.ubicacion');

    // Consultas y Reportes Gerenciales (Excel CSV y PDF)
    Route::get('/reportes', [ReporteController::class, 'index'])->name('reportes.index');
    Route::get('/reportes/exportar-csv', [ReporteController::class, 'exportarCsv'])->name('reportes.csv');
    Route::get('/reportes/imprimir-pdf', [ReporteController::class, 'vistaPdf'])->name('reportes.pdf');
});
LARAVEL_FILE,

    // =========================================================================
    // 6. VISTAS BLADE CON IDENTIDAD VISUAL DONSEN® (LOGO VECTORIAL + TAILWIND)
    // =========================================================================
    'resources/views/components/donsen-logo.blade.php' => <<<'LARAVEL_FILE'
@props(['width' => 155])
@php
    $height = (int) round($width * 0.43);
@endphp
<svg width="{{ $width }}" height="{{ $height }}" viewBox="0 0 365 156" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="Logo Oficial Donsen">
    <g transform="translate(22, 86) skewX(-15)">
        <text x="0" y="0" font-family="'Inter', 'Arial Black', sans-serif" font-weight="900" font-size="84" letter-spacing="-3.5" fill="#373A39">donsen</text>
    </g>
    <text x="331" y="34" font-family="'Inter', Arial, sans-serif" font-weight="700" font-size="22" fill="#373A39">®</text>
    <!-- Tubería Superior Verde Donsen -->
    <path d="M 62 113 L 333 61 L 335 103 L 64 126 Z" fill="#197328" />
    <ellipse cx="334" cy="82" rx="13.5" ry="21" fill="#197328" />
    <ellipse cx="334" cy="82" rx="9.5" ry="15.5" fill="#FFFFFF" />
    <ellipse cx="334" cy="82" rx="6" ry="10.5" fill="#197328" />
    <!-- Separador Blanco -->
    <path d="M 22 121 L 315 88 L 318 140 L 22 151 Z" fill="#FFFFFF" />
    <!-- Tubería Inferior Verde Donsen -->
    <path d="M 22 124 L 313 92 L 315 137 L 22 148 Z" fill="#197328" />
    <ellipse cx="22" cy="136" rx="11" ry="12" fill="#197328" />
    <ellipse cx="314" cy="114.5" rx="14.5" ry="22.5" fill="#197328" />
    <ellipse cx="314" cy="114.5" rx="10.2" ry="16.8" fill="#FFFFFF" />
    <ellipse cx="314" cy="114.5" rx="6.2" ry="11" fill="#197328" />
</svg>
LARAVEL_FILE,

    'resources/views/layouts/app.blade.php' => <<<'LARAVEL_FILE'
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Donsen® | Control de Asistencia QR')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #F4F6F5; color: #373A39; }
    </style>
</head>
<body class="min-h-screen flex flex-col antialiased">

    <header class="bg-white border-b border-gray-200 sticky top-0 z-30 shadow-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3.5">
                <a href="{{ Auth::check() ? route('dashboard') : route('login') }}">
                    <x-donsen-logo :width="130" />
                </a>
                <div class="hidden sm:block h-8 w-px bg-gray-200"></div>
                <div class="hidden sm:block">
                    <div class="text-sm font-bold text-[#373A39]">Control de Asistencia QR</div>
                    <div class="text-[11px] text-gray-500">Laravel MVC • Base de Datos (db_asistencia_donsen)</div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                @auth
                    <div class="hidden md:flex items-center gap-2 bg-[#E8F3EA] px-3 py-1.5 rounded-xl border border-[#197328]/20">
                        <span class="w-2 h-2 rounded-full bg-[#197328]"></span>
                        <span class="text-xs font-bold text-[#373A39]">{{ Auth::user()->name }}</span>
                        <span class="text-[11px] text-[#197328] font-semibold">({{ Auth::user()->rol }})</span>
                    </div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="px-3.5 py-2 rounded-xl bg-gray-100 hover:bg-red-50 text-xs font-semibold text-gray-700 hover:text-red-600 transition">
                            <i class="fa-solid fa-right-from-bracket mr-1"></i>Cerrar Sesión
                        </button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="px-3.5 py-2 rounded-xl bg-[#373A39] text-white text-xs font-semibold">
                        Acceso Administrativo
                    </a>
                @endauth
            </div>
        </div>

        @auth
        <nav class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-2.5 flex items-center gap-2 overflow-x-auto">
            <a href="{{ route('dashboard') }}" class="px-4 py-2 rounded-xl text-xs sm:text-sm font-bold {{ request()->routeIs('dashboard') ? 'bg-[#197328] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
                <i class="fa-solid fa-chart-pie mr-1.5"></i>Dashboard
            </a>
            <a href="{{ route('empleados.index') }}" class="px-4 py-2 rounded-xl text-xs sm:text-sm font-bold {{ request()->routeIs('empleados.*') ? 'bg-[#197328] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
                <i class="fa-solid fa-users-gear mr-1.5"></i>Empleados y Códigos QR
            </a>
            <a href="{{ route('scanner.index') }}" class="px-4 py-2 rounded-xl text-xs sm:text-sm font-bold {{ request()->routeIs('scanner.*') ? 'bg-[#197328] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
                <i class="fa-solid fa-qrcode mr-1.5"></i>Marcaje QR (Empleado)
            </a>
            <a href="{{ route('reportes.index') }}" class="px-4 py-2 rounded-xl text-xs sm:text-sm font-bold {{ request()->routeIs('reportes.*') ? 'bg-[#197328] text-white' : 'text-gray-600 hover:bg-gray-100' }}">
                <i class="fa-solid fa-file-export mr-1.5"></i>Consultas y Reportes
            </a>
        </nav>
        @endauth
    </header>

    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">
        @if(session('success'))
            <div class="mb-5 p-4 rounded-2xl bg-[#E8F3EA] border border-[#197328]/30 text-[#197328] text-sm font-semibold flex items-center gap-2.5">
                <i class="fa-solid fa-circle-check"></i>
                <span>{{ session('success') }}</span>
            </div>
        @endif

        @if($errors->any())
            <div class="mb-5 p-4 rounded-2xl bg-red-50 border border-red-200 text-red-700 text-sm">
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>

</body>
</html>
LARAVEL_FILE,

    'resources/views/auth/login.blade.php' => <<<'LARAVEL_FILE'
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión | Donsen® Control de Asistencia QR</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="min-h-screen bg-[#F4F6F5] flex items-center justify-center p-4">
    <div class="max-w-4xl w-full grid grid-cols-1 lg:grid-cols-12 bg-white rounded-2xl shadow-xl border border-gray-200 overflow-hidden">
        <div class="lg:col-span-5 bg-[#373A39] text-white p-8 flex flex-col justify-between">
            <div>
                <div class="bg-white rounded-xl p-4 inline-block shadow-md mb-6">
                    <x-donsen-logo :width="170" />
                </div>
                <h1 class="text-2xl font-extrabold leading-snug">Sistema de Control de Asistencia QR</h1>
                <p class="text-gray-300 text-sm mt-2">
                    Automatización del registro de entradas y salidas mediante doble validación QR para la empresa <strong>Donsen</strong>.
                </p>
            </div>

            <div class="mt-8 pt-6 border-t border-gray-600">
                <a href="{{ route('scanner.index') }}" class="w-full flex items-center justify-between bg-[#197328] hover:bg-[#13591F] text-white font-bold p-4 rounded-xl transition">
                    <div>
                        <div class="text-sm">Interfaz Móvil de Marcaje QR</div>
                        <div class="text-xs text-green-100 font-normal">Acceso directo para Empleados</div>
                    </div>
                    <i class="fa-solid fa-qrcode text-xl"></i>
                </a>
            </div>
        </div>

        <div class="lg:col-span-7 p-8 sm:p-10 flex flex-col justify-center">
            <h2 class="text-2xl font-bold text-[#373A39]">Acceso Administrativo</h2>
            <p class="text-sm text-gray-500 mt-1 mb-6">Ingrese sus credenciales de Director General o Administradora.</p>

            @if($errors->any())
                <div class="mb-4 p-3 rounded-xl bg-red-50 border border-red-200 text-red-700 text-xs font-semibold">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login.post') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Correo Electrónico</label>
                    <input type="email" name="email" value="{{ old('email', 'director@donsen.com') }}" required
                           class="w-full px-4 py-3 rounded-xl border border-gray-300 text-sm focus:ring-2 focus:ring-[#197328] focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase text-gray-600 mb-1">Contraseña</label>
                    <input type="password" name="password" value="password123" required
                           class="w-full px-4 py-3 rounded-xl border border-gray-300 text-sm focus:ring-2 focus:ring-[#197328] focus:outline-none">
                </div>
                <button type="submit" class="w-full py-3 rounded-xl bg-[#373A39] hover:bg-black text-white font-bold text-sm transition">
                    <i class="fa-solid fa-right-to-bracket mr-2"></i>Iniciar Sesión
                </button>
            </form>
        </div>
    </div>
</body>
</html>
LARAVEL_FILE,

    'resources/views/dashboard.blade.php' => <<<'LARAVEL_FILE'
@extends('layouts.app')
@section('title', 'Dashboard Gerencial | Donsen®')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-extrabold text-[#373A39]">Panel Principal de Asistencia</h1>
            <p class="text-sm text-gray-500">Supervisión en tiempo real — Jornada {{ \Carbon\Carbon::parse($hoy)->format('d/m/Y') }}</p>
        </div>
        <div class="flex gap-2.5">
            <a href="{{ route('scanner.index') }}" class="px-4 py-2.5 rounded-xl bg-[#197328] hover:bg-[#13591F] text-white text-xs font-bold">
                <i class="fa-solid fa-qrcode mr-1.5"></i>Terminal de Marcaje
            </a>
            <a href="{{ route('reportes.csv') }}" class="px-4 py-2.5 rounded-xl bg-white border border-gray-200 text-[#373A39] text-xs font-bold">
                <i class="fa-solid fa-file-csv text-[#197328] mr-1.5"></i>Exportar CSV
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white p-5 rounded-2xl border border-gray-200">
            <div class="text-xs font-bold uppercase text-gray-400">Empleados Activos</div>
            <div class="text-3xl font-extrabold text-[#373A39] mt-1">{{ $totalEmpleados }}</div>
            <div class="text-xs text-gray-500 mt-1">Plantilla censal Donsen</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-gray-200">
            <div class="text-xs font-bold uppercase text-gray-400">Asistencias Hoy</div>
            <div class="text-3xl font-extrabold text-[#197328] mt-1">{{ $presentesHoy }}</div>
            <div class="text-xs text-[#197328] font-semibold mt-1">{{ $porcentajeAsistencia }}% de puntualidad</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-gray-200">
            <div class="text-xs font-bold uppercase text-gray-400">Retardos Hoy</div>
            <div class="text-3xl font-extrabold text-amber-600 mt-1">{{ $retardosHoy }}</div>
            <div class="text-xs text-gray-500 mt-1">Fuera de margen de tolerancia</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-gray-200">
            <div class="text-xs font-bold uppercase text-gray-400">Sin Registro Hoy</div>
            <div class="text-3xl font-extrabold text-rose-600 mt-1">{{ $ausentesHoy }}</div>
            <div class="text-xs text-gray-500 mt-1">Pendientes por marcar</div>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="p-5 border-b border-gray-100">
            <h2 class="text-base font-bold text-[#373A39]">Monitoreo de Jornada Actual</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-[#F4F6F5] text-gray-500 text-xs uppercase border-b border-gray-200">
                        <th class="py-3 px-4">Trabajador</th>
                        <th class="py-3 px-4">Departamento / Cargo</th>
                        <th class="py-3 px-4">Turno</th>
                        <th class="py-3 px-4">Entrada</th>
                        <th class="py-3 px-4">Salida</th>
                        <th class="py-3 px-4">Estatus</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm">
                    @foreach($empleadosActivos as $emp)
                        @php $reg = $asistenciasHoy->get($emp->id); @endphp
                        <tr>
                            <td class="py-3 px-4">
                                <div class="font-bold text-[#373A39]">{{ $emp->nombres_apellidos }}</div>
                                <div class="text-xs text-gray-400">{{ $emp->cedula }}</div>
                            </td>
                            <td class="py-3 px-4">
                                <div class="text-xs font-bold">{{ $emp->departamento->nombre_departamento }}</div>
                                <div class="text-xs text-gray-500">{{ $emp->cargo }}</div>
                            </td>
                            <td class="py-3 px-4 text-xs text-gray-600">
                                {{ substr($emp->horario->hora_entrada, 0, 5) }} - {{ substr($emp->horario->hora_salida, 0, 5) }}
                            </td>
                            <td class="py-3 px-4 font-bold">
                                {{ $reg && $reg->hora_entrada ? substr($reg->hora_entrada, 0, 5) : '--:--' }}
                            </td>
                            <td class="py-3 px-4 font-bold">
                                {{ $reg && $reg->hora_salida ? substr($reg->hora_salida, 0, 5) : '--:--' }}
                            </td>
                            <td class="py-3 px-4">
                                @if(!$reg || !$reg->hora_entrada)
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-600">Sin registro</span>
                                @elseif($reg->estatus === 'Retardo')
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-amber-50 text-amber-700">Retardo</span>
                                @else
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold bg-[#E8F3EA] text-[#197328]">A tiempo</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
LARAVEL_FILE,

    'resources/views/empleados/index.blade.php' => <<<'LARAVEL_FILE'
@extends('layouts.app')
@section('title', 'Empleados y Códigos QR | Donsen®')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-extrabold text-[#373A39]">Gestión de Personal y Códigos QR</h1>
            <p class="text-sm text-gray-500">Registro CRUD de empleados, control de estado y emisión de credenciales QR</p>
        </div>
        <button type="button" onclick="document.getElementById('modal-nuevo-emp').classList.remove('hidden')"
                class="px-4 py-2.5 rounded-xl bg-[#197328] hover:bg-[#13591F] text-white text-xs font-bold">
            <i class="fa-solid fa-user-plus mr-1.5"></i>Nuevo Empleado
        </button>
    </div>

    @if($sedeQr)
    <div class="bg-white p-5 rounded-2xl border border-gray-200 space-y-4">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <div id="qr-sede-preview" class="p-2.5 bg-white border border-gray-200 rounded-xl shrink-0 cursor-pointer"
                     onclick="document.getElementById('modal-cartel-sede').classList.remove('hidden')"
                     title="Clic para ampliar QR de entrada"></div>
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#E8F3EA] text-[#197328]">QR Fijo Físico + Geocerca GPS</span>
                        @if($sedeQr->latitud && $sedeQr->longitud)
                            <a href="https://www.google.com/maps?q={{ $sedeQr->latitud }},{{ $sedeQr->longitud }}" target="_blank"
                               class="text-xs font-bold text-blue-600 hover:underline">
                                <i class="fa-solid fa-location-dot mr-1"></i>Ver Sede en Google Maps
                            </a>
                        @endif
                    </div>
                    <h2 class="text-base font-bold text-[#373A39] mt-1">{{ $sedeQr->nombre_sede }} (Radio permitido: {{ $sedeQr->radio_metros }}m)</h2>
                    <p class="text-xs text-gray-500">{{ $sedeQr->ubicacion }} • Código: <code class="font-bold text-[#197328]">{{ $sedeQr->codigo_qr_fijo }}</code></p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" onclick="document.getElementById('modal-cartel-sede').classList.remove('hidden')"
                        class="px-3.5 py-2 rounded-xl bg-[#197328] hover:bg-[#13591F] text-white text-xs font-bold">
                    <i class="fa-solid fa-expand mr-1.5"></i>Ver Cartel QR Grande
                </button>
                <button type="button" onclick="descargarCartelQRSede()"
                        class="px-3.5 py-2 rounded-xl bg-[#373A39] hover:bg-black text-white text-xs font-bold">
                    <i class="fa-solid fa-download mr-1.5"></i>Descargar Cartel (PNG)
                </button>
                <button type="button" onclick="document.getElementById('form-gps-sede').classList.toggle('hidden')"
                        class="px-3.5 py-2 rounded-xl bg-[#E8F3EA] text-[#197328] text-xs font-bold">
                    <i class="fa-solid fa-map-location-dot mr-1.5"></i>Calibrar GPS Sede
                </button>
            </div>
        </div>

        <form id="form-gps-sede" method="POST" action="{{ route('sede.ubicacion', $sedeQr) }}" class="hidden pt-4 border-t border-gray-100 grid grid-cols-1 sm:grid-cols-6 gap-3 items-end">
            @csrf @method('PUT')
            <input type="hidden" name="nombre_sede" value="{{ $sedeQr->nombre_sede }}">
            <input type="hidden" name="ubicacion" value="{{ $sedeQr->ubicacion }}">
            <div class="sm:col-span-2">
                <label class="block text-[11px] font-bold text-gray-500 mb-1">Latitud Sede</label>
                <input type="text" id="input-sede-lat" name="latitud" value="{{ $sedeQr->latitud }}" required class="w-full px-3 py-2 rounded-xl border text-xs font-mono">
            </div>
            <div class="sm:col-span-2">
                <label class="block text-[11px] font-bold text-gray-500 mb-1">Longitud Sede</label>
                <input type="text" id="input-sede-lng" name="longitud" value="{{ $sedeQr->longitud }}" required class="w-full px-3 py-2 rounded-xl border text-xs font-mono">
            </div>
            <div>
                <label class="block text-[11px] font-bold text-gray-500 mb-1">Radio (metros)</label>
                <input type="number" name="radio_metros" value="{{ $sedeQr->radio_metros }}" min="20" max="5000" required class="w-full px-3 py-2 rounded-xl border text-xs">
            </div>
            <div class="flex gap-1.5">
                <button type="button" onclick="capturarUbicacionActualSede()" title="Tomar GPS de este dispositivo"
                        class="px-3 py-2 rounded-xl bg-blue-600 text-white text-xs font-bold">
                    <i class="fa-solid fa-crosshairs"></i>
                </button>
                <button type="submit" class="flex-1 py-2 px-3 rounded-xl bg-[#197328] text-white text-xs font-bold">
                    Guardar
                </button>
            </div>
        </form>
    </div>
    @endif

    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-[#F4F6F5] text-gray-500 text-xs uppercase border-b border-gray-200">
                        <th class="py-3 px-4">Cédula</th>
                        <th class="py-3 px-4">Nombres y Apellidos</th>
                        <th class="py-3 px-4">Cargo / Departamento</th>
                        <th class="py-3 px-4">Horario</th>
                        <th class="py-3 px-4">Dispositivo Móvil</th>
                        <th class="py-3 px-4">Estado</th>
                        <th class="py-3 px-4 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm">
                    @foreach($empleados as $emp)
                    <tr>
                        <td class="py-3 px-4 font-mono text-xs font-bold">{{ $emp->cedula }}</td>
                        <td class="py-3 px-4 font-bold text-[#373A39]">{{ $emp->nombres_apellidos }}</td>
                        <td class="py-3 px-4 text-xs">{{ $emp->cargo }} ({{ $emp->departamento->nombre_departamento }})</td>
                        <td class="py-3 px-4 text-xs">{{ $emp->horario->turno }}</td>
                        <td class="py-3 px-4 text-xs">
                            @if($emp->device_token)
                                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 font-bold text-[11px]">
                                    <i class="fa-solid fa-mobile-screen-button"></i>
                                    <span>Vinculado</span>
                                </div>
                            @else
                                <span class="text-gray-400 text-[11px]">Sin vincular</span>
                            @endif
                        </td>
                        <td class="py-3 px-4">
                            <form method="POST" action="{{ route('empleados.toggle', $emp) }}">
                                @csrf @method('PATCH')
                                <button type="submit" class="px-2.5 py-1 rounded-full text-xs font-bold {{ $emp->estado ? 'bg-[#E8F3EA] text-[#197328]' : 'bg-gray-200 text-gray-600' }}">
                                    {{ $emp->estado ? 'Activo' : 'Inactivo' }}
                                </button>
                            </form>
                        </td>
                        <td class="py-3 px-4 text-right whitespace-nowrap">
                            <div class="inline-flex items-center gap-1.5">
                                @if($emp->device_token)
                                <form method="POST" action="{{ route('empleados.resetDevice', $emp) }}"
                                      onsubmit="return confirm('¿Desvincular el teléfono registrado de {{ $emp->nombres_apellidos }}?');">
                                    @csrf @method('PATCH')
                                    <button type="submit" title="Desvincular teléfono móvil"
                                            class="px-2.5 py-1.5 rounded-xl bg-amber-50 hover:bg-amber-500 text-amber-700 hover:text-white text-xs font-bold transition">
                                        <i class="fa-solid fa-mobile-screen"></i>
                                    </button>
                                </form>
                                @endif
                                <button type="button"
                                        onclick="mostrarCarnetQR('{{ $emp->nombres_apellidos }}', '{{ $emp->cargo }}', '{{ $emp->cedula }}', '{{ $emp->codigo_qr_empleado }}')"
                                        class="px-3 py-1.5 rounded-xl bg-[#197328] hover:bg-[#13591F] text-white text-xs font-bold">
                                    <i class="fa-solid fa-qrcode mr-1"></i>Ver QR
                                </button>
                                <form method="POST" action="{{ route('empleados.destroy', $emp) }}"
                                      onsubmit="return confirm('¿Eliminar a {{ $emp->nombres_apellidos }} y todo su historial de asistencia?');">
                                    @csrf @method('DELETE')
                                    <button type="submit" title="Eliminar empleado"
                                            class="px-2.5 py-1.5 rounded-xl bg-red-50 hover:bg-red-600 text-red-600 hover:text-white text-xs font-bold transition">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Nuevo Empleado -->
<div id="modal-nuevo-emp" class="hidden fixed inset-0 z-40 bg-black/50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4">
        <div class="flex justify-between items-center border-b pb-3">
            <h3 class="text-base font-bold">Registrar Nuevo Empleado</h3>
            <button type="button" onclick="document.getElementById('modal-nuevo-emp').classList.add('hidden')"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <form method="POST" action="{{ route('empleados.store') }}" class="space-y-3">
            @csrf
            <input type="text" name="cedula" placeholder="Cédula (Ej: V-25.123.456)" required class="w-full px-3 py-2 rounded-xl border text-sm">
            <input type="text" name="nombres_apellidos" placeholder="Nombres y Apellidos" required class="w-full px-3 py-2 rounded-xl border text-sm">
            <input type="text" name="cargo" placeholder="Cargo" required class="w-full px-3 py-2 rounded-xl border text-sm">
            <select name="departamento_id" required class="w-full px-3 py-2 rounded-xl border text-sm bg-white">
                @foreach($departamentos as $d)
                    <option value="{{ $d->id }}">{{ $d->nombre_departamento }}</option>
                @endforeach
            </select>
            <select name="horario_id" required class="w-full px-3 py-2 rounded-xl border text-sm bg-white">
                @foreach($horarios as $h)
                    <option value="{{ $h->id }}">{{ $h->turno }} ({{ substr($h->hora_entrada,0,5) }})</option>
                @endforeach
            </select>
            <input type="hidden" name="estado" value="1">
            <button type="submit" class="w-full py-2.5 rounded-xl bg-[#197328] text-white text-xs font-bold">Guardar y Generar QR</button>
        </form>
    </div>
</div>

<!-- Modal Cartel QR Grande de la Sede (Alta Resolución para Escaneo Rápido) -->
@if($sedeQr)
<div id="modal-cartel-sede" class="hidden fixed inset-0 z-40 bg-black/60 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-sm w-full p-6 text-center space-y-4 border-2 border-[#197328]">
        <div class="flex justify-center"><x-donsen-logo :width="150" /></div>
        <div class="text-xs font-extrabold uppercase tracking-wider text-[#197328]">Punto de Marcaje de Asistencia</div>
        <div class="bg-white p-5 border-2 border-gray-200 rounded-2xl inline-block">
            <div id="qr-sede-hd"></div>
        </div>
        <div>
            <div class="text-sm font-extrabold text-[#373A39]">{{ $sedeQr->nombre_sede }}</div>
            <div class="text-xs text-gray-500">{{ $sedeQr->ubicacion }}</div>
            <code class="inline-block mt-1.5 text-xs font-bold bg-[#E8F3EA] text-[#197328] px-3 py-1 rounded-lg">{{ $sedeQr->codigo_qr_fijo }}</code>
        </div>
        <div class="grid grid-cols-2 gap-2">
            <button type="button" onclick="descargarCartelQRSede()" class="py-2.5 rounded-xl bg-[#197328] text-white text-xs font-bold">
                <i class="fa-solid fa-download mr-1"></i>Descargar PNG
            </button>
            <button type="button" onclick="document.getElementById('modal-cartel-sede').classList.add('hidden')" class="py-2.5 rounded-xl bg-[#373A39] text-white text-xs font-bold">
                Cerrar
            </button>
        </div>
    </div>
</div>
@endif

<!-- Modal Carnet QR Individual -->
<div id="modal-carnet-qr" class="hidden fixed inset-0 z-40 bg-black/50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-sm w-full p-6 text-center space-y-4">
        <div class="flex justify-center"><x-donsen-logo :width="135" /></div>
        <div id="carnet-qr-box" class="flex justify-center p-4 bg-white border rounded-xl"></div>
        <div>
            <div id="carnet-nombre" class="text-base font-extrabold text-[#373A39]"></div>
            <div id="carnet-cargo" class="text-xs text-[#197328] font-bold"></div>
            <div id="carnet-cedula" class="text-xs text-gray-500"></div>
            <code id="carnet-token" class="block mt-1 text-[11px] bg-gray-100 py-1 rounded"></code>
        </div>
        <button type="button" onclick="document.getElementById('modal-carnet-qr').classList.add('hidden')" class="w-full py-2 rounded-xl bg-[#373A39] text-white text-xs font-bold">Cerrar</button>
    </div>
</div>

<script>
    const urlSedeQr = '{{ url("/marcaje-qr?sede=" . ($sedeQr->codigo_qr_fijo ?? "DONSEN-SEDE-CCS-2026")) }}';

    document.addEventListener('DOMContentLoaded', () => {
        if (typeof QRCode === 'undefined') return;
        const sedeBox = document.getElementById('qr-sede-preview');
        if (sedeBox) {
            new QRCode(sedeBox, {
                text: urlSedeQr,
                width: 104,
                height: 104,
                colorDark: '#000000',
                colorLight: '#FFFFFF',
                correctLevel: QRCode.CorrectLevel.M
            });
        }
        const sedeHdBox = document.getElementById('qr-sede-hd');
        if (sedeHdBox) {
            new QRCode(sedeHdBox, {
                text: urlSedeQr,
                width: 240,
                height: 240,
                colorDark: '#000000',
                colorLight: '#FFFFFF',
                correctLevel: QRCode.CorrectLevel.M
            });
        }
    });

    function capturarUbicacionActualSede() {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition((pos) => {
            document.getElementById('input-sede-lat').value = pos.coords.latitude.toFixed(7);
            document.getElementById('input-sede-lng').value = pos.coords.longitude.toFixed(7);
        }, () => {}, { enableHighAccuracy: true });
    }

    function descargarCartelQRSede() {
        const qrCanvas = document.querySelector('#qr-sede-hd canvas') || document.querySelector('#qr-sede-preview canvas');
        if (!qrCanvas) return;

        // Generar cartel imprimible HD (480x620) con amplio margen blanco para lectura instantánea
        const poster = document.createElement('canvas');
        poster.width = 480;
        poster.height = 620;
        const ctx = poster.getContext('2d');

        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, 480, 620);
        ctx.strokeStyle = '#197328';
        ctx.lineWidth = 12;
        ctx.strokeRect(6, 6, 468, 608);

        ctx.fillStyle = '#373A39';
        ctx.font = 'italic 900 48px Arial, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText('donsen®', 240, 74);

        ctx.fillStyle = '#197328';
        ctx.fillRect(110, 88, 260, 8);
        ctx.fillRect(90, 100, 280, 10);

        ctx.fillStyle = '#197328';
        ctx.font = 'bold 16px Arial, sans-serif';
        ctx.fillText('CONTROL DE ASISTENCIA - ENTRADA SEDE', 240, 138);

        // Dibujar QR de alta resolución con margen blanco alrededor
        ctx.drawImage(qrCanvas, 90, 165, 300, 300);

        ctx.fillStyle = '#373A39';
        ctx.font = 'bold 16px monospace';
        ctx.fillText('{{ $sedeQr->codigo_qr_fijo ?? "DONSEN-SEDE-CCS-2026" }}', 240, 505);

        ctx.fillStyle = '#4B5563';
        ctx.font = '14px Arial, sans-serif';
        ctx.fillText('Escanee este código con su teléfono al entrar o salir', 240, 540);
        ctx.fillText('{{ $sedeQr->nombre_sede ?? "Sede Principal Caracas" }}', 240, 565);

        const link = document.createElement('a');
        link.download = 'Cartel_QR_Entrada_Donsen_HD.png';
        link.href = poster.toDataURL('image/png');
        link.click();
    }

    function mostrarCarnetQR(nombre, cargo, cedula, token) {
        document.getElementById('carnet-nombre').textContent = nombre;
        document.getElementById('carnet-cargo').textContent = cargo;
        document.getElementById('carnet-cedula').textContent = 'C.I: ' + cedula;
        document.getElementById('carnet-token').textContent = token;
        const box = document.getElementById('carnet-qr-box');
        box.innerHTML = '';
        new QRCode(box, { text: token, width: 200, height: 200, colorDark: '#000000', colorLight: '#FFFFFF', correctLevel: QRCode.CorrectLevel.M });
        document.getElementById('modal-carnet-qr').classList.remove('hidden');
    }
</script>
@endsection
LARAVEL_FILE,

    'resources/views/scanner/index.blade.php' => <<<'LARAVEL_FILE'
@extends('layouts.app')
@section('title', 'Marcaje QR Empleado | Donsen®')

@section('content')
<div class="max-w-lg mx-auto space-y-5">
    <div class="bg-white p-5 rounded-2xl border border-gray-200 text-center">
        <span class="px-3 py-1 rounded-full bg-[#E8F3EA] text-[#197328] text-xs font-bold">
            <i class="fa-solid fa-location-dot mr-1"></i>Geocerca GPS + Dispositivo Único • Donsen
        </span>
        <h1 class="text-xl font-extrabold text-[#373A39] mt-2">Registro de Asistencia Laboral</h1>
        <p class="text-xs text-gray-500 mt-1">Validación física en sede mediante GPS en mapa y teléfono vinculado</p>
        <div id="gps-status-pill" class="mt-2.5 inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-amber-50 text-amber-700 text-[11px] font-semibold">
            <i class="fa-solid fa-satellite-dish"></i>
            <span id="gps-status-text">Obteniendo coordenadas GPS del mapa...</span>
        </div>
    </div>

    <!-- PASO 1: QR DE LA ENTRADA -->
    <div id="paso-1-card" class="{{ $sedePrevalidada ? 'hidden' : '' }} bg-white p-6 rounded-2xl border border-gray-200 space-y-4">
        <h2 class="text-base font-bold text-[#373A39]">1. Escanear QR Fijo de la Entrada de Donsen</h2>
        <p class="text-xs text-gray-500">Apunte la cámara de su celular al cartel QR físico ubicado en la entrada principal (<code class="font-bold text-[#197328]">{{ $sedeQr->codigo_qr_fijo ?? 'DONSEN-SEDE-CCS-2026' }}</code>).</p>

        <div id="paso-1-error" class="hidden p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-semibold"></div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
            <button type="button" onclick="iniciarCamara('sede')" class="py-3 px-4 rounded-xl bg-[#373A39] text-white text-xs font-bold">
                <i class="fa-solid fa-camera mr-1.5"></i>Escanear QR Entrada
            </button>
            <button type="button" onclick="validarSede('{{ $sedeQr->codigo_qr_fijo ?? 'DONSEN-SEDE-CCS-2026' }}')" class="py-3 px-4 rounded-xl bg-[#197328] text-white text-xs font-bold">
                <i class="fa-solid fa-bolt mr-1.5"></i>Simular Entrada Sede
            </button>
        </div>
    </div>

    <div id="visor-camara-card" class="hidden bg-white p-4 rounded-2xl border-2 border-[#197328] space-y-2.5">
        <div id="camara-estado-msg" class="text-center text-xs font-bold text-[#197328]">
            <i class="fa-solid fa-camera mr-1"></i>Enfoque el código QR dentro del recuadro...
        </div>
        <div id="qr-reader" class="w-full rounded-xl overflow-hidden bg-black min-h-[260px]"></div>
        <button type="button" onclick="detenerCamara()" class="w-full py-2.5 rounded-xl bg-red-50 text-red-600 text-xs font-bold">Cerrar Cámara</button>
    </div>

    <!-- PASO 2: IDENTIFICACIÓN PERSONAL + BLOQUEO DE DISPOSITIVO -->
    <div id="paso-2-card" class="{{ $sedePrevalidada ? '' : 'hidden' }} bg-white p-6 rounded-2xl border border-gray-200 space-y-4">
        <div class="p-3 rounded-xl bg-[#E8F3EA] text-[#197328] text-xs font-bold flex items-center justify-between gap-2">
            <span><i class="fa-solid fa-check-circle mr-1"></i>Sede Verificada: <span id="sede-activa-label">{{ $sedePrevalidada->codigo_qr_fijo ?? '' }}</span></span>
            <button type="button" onclick="reiniciarPasoSede()" class="text-[11px] underline text-[#373A39] hover:text-rose-600 shrink-0">
                Cambiar QR
            </button>
        </div>

        <h2 class="text-base font-bold text-[#373A39]">2. Confirme su Identidad Personal</h2>
        <p class="text-xs text-gray-500">
            Al registrar su asistencia, este teléfono quedará vinculado exclusivamente a su cédula para evitar marcajes por terceros.
        </p>

        <div class="space-y-3">
            <div>
                <label class="block text-xs font-bold text-[#373A39] mb-1">Ingrese su Cédula de Identidad:</label>
                <input type="text" id="input-identificador-emp" placeholder="Ej: V-24.512.889"
                       class="w-full px-3.5 py-3 rounded-xl border border-gray-300 text-sm font-semibold focus:ring-2 focus:ring-[#197328] focus:outline-none">
            </div>

            <button type="button" onclick="enviarMarcajeAjax(document.getElementById('input-identificador-emp').value, false)"
                    class="w-full py-3.5 rounded-xl bg-[#197328] hover:bg-[#13591F] text-white text-xs sm:text-sm font-bold shadow-xs">
                <i class="fa-solid fa-fingerprint mr-1.5"></i>Registrar mi Entrada / Salida
            </button>

            <button type="button" onclick="iniciarCamara('empleado')"
                    class="w-full py-2.5 rounded-xl bg-[#F4F6F5] hover:bg-gray-200 text-[#373A39] text-xs font-bold">
                <i class="fa-solid fa-qrcode mr-1.5"></i>O escanear mi Carnet QR Individual
            </button>
        </div>

        @auth
        <div class="pt-4 border-t border-gray-200 space-y-2.5">
            <div class="text-[11px] font-bold uppercase text-amber-700">
                <i class="fa-solid fa-user-shield mr-1"></i>Modo Simulación (Solo Administrador Logueado):
            </div>
            <select id="select-empleado-qr" class="w-full px-3 py-2 rounded-xl border bg-white text-xs font-semibold">
                @foreach($empleados as $emp)
                    <option value="{{ $emp->codigo_qr_empleado }}">{{ $emp->nombres_apellidos }} ({{ $emp->cedula }})</option>
                @endforeach
            </select>
            <button type="button" onclick="enviarMarcajeAjax(document.getElementById('select-empleado-qr').value, true)"
                    class="w-full py-2.5 rounded-xl bg-[#373A39] text-white text-xs font-bold">
                Simular Marcaje como Administrador
            </button>
        </div>
        @endauth
    </div>

    <div id="resultado-marcaje-box" class="hidden bg-white p-6 rounded-2xl border-2 shadow-md space-y-2"></div>
</div>

<script>
    const sedeCodigoOficial = @json($sedeQr->codigo_qr_fijo ?? 'DONSEN-SEDE-CCS-2026');
    let qrSedeValidado = @json($sedePrevalidada->codigo_qr_fijo ?? null);
    let html5Qr = null;
    let gpsCoords = { lat: null, lng: null };

    function solicitarUbicacionGPS() {
        const pill = document.getElementById('gps-status-pill');
        const txt = document.getElementById('gps-status-text');
        if (!navigator.geolocation) {
            pill.className = 'mt-2.5 inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-red-50 text-red-700 text-[11px] font-semibold';
            txt.textContent = 'Navegador sin soporte GPS.';
            return;
        }
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                gpsCoords.lat = pos.coords.latitude;
                gpsCoords.lng = pos.coords.longitude;
                const prec = Math.round(pos.coords.accuracy);
                pill.className = 'mt-2.5 inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-[#E8F3EA] text-[#197328] text-[11px] font-bold';
                txt.innerHTML = `GPS Activo (${gpsCoords.lat.toFixed(5)}, ${gpsCoords.lng.toFixed(5)} • ±${prec}m) • <a href="https://www.google.com/maps?q=${gpsCoords.lat},${gpsCoords.lng}" target="_blank" class="underline">Ver Mapa</a>`;
            },
            () => {
                pill.className = 'mt-2.5 inline-flex items-center gap-1.5 px-3 py-1 rounded-lg bg-red-50 text-red-700 text-[11px] font-bold';
                txt.textContent = 'Permiso de GPS denegado. Actívelo en su celular para poder marcar.';
            },
            { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
        );
    }

    document.addEventListener('DOMContentLoaded', solicitarUbicacionGPS);

    function obtenerDeviceToken() {
        let token = localStorage.getItem('donsen_device_token');
        if (!token) {
            const randomPart = (window.crypto && crypto.randomUUID)
                ? crypto.randomUUID()
                : 'DEV-' + Date.now() + '-' + Math.random().toString(36).substring(2, 12);
            token = 'DONSEN-PHONE-' + randomPart;
            localStorage.setItem('donsen_device_token', token);
        }
        return token;
    }

    function validarSede(codigo) {
        let limpio = (codigo || '').trim();
        if (limpio.includes('sede=')) {
            try {
                const u = new URL(limpio);
                limpio = u.searchParams.get('sede') || limpio;
            } catch (e) {}
        }
        limpio = limpio.toUpperCase();

        const errBox = document.getElementById('paso-1-error');
        if (limpio !== sedeCodigoOficial.toUpperCase()) {
            errBox.classList.remove('hidden');
            if (limpio.startsWith('DONSEN-EMP-')) {
                errBox.textContent = 'Escaneaste un Carnet Individual de Empleado (' + limpio + '). En el Paso 1 debes escanear el QR Fijo de la Entrada de Donsen (' + sedeCodigoOficial + ').';
            } else {
                errBox.textContent = 'Código QR de sede incorrecto (' + limpio + '). Escanee el QR oficial ubicado en la entrada de Donsen (' + sedeCodigoOficial + ').';
            }
            return;
        }

        errBox.classList.add('hidden');
        qrSedeValidado = limpio;
        document.getElementById('sede-activa-label').textContent = qrSedeValidado;
        document.getElementById('paso-1-card').classList.add('hidden');
        document.getElementById('paso-2-card').classList.remove('hidden');
        document.getElementById('resultado-marcaje-box').classList.add('hidden');
    }

    function reiniciarPasoSede() {
        detenerCamara();
        qrSedeValidado = null;
        document.getElementById('paso-1-error').classList.add('hidden');
        document.getElementById('paso-2-card').classList.add('hidden');
        document.getElementById('resultado-marcaje-box').classList.add('hidden');
        document.getElementById('paso-1-card').classList.remove('hidden');
    }

    async function iniciarCamara(objetivo) {
        await detenerCamara();
        const visorCard = document.getElementById('visor-camara-card');
        const msgEl = document.getElementById('camara-estado-msg');
        visorCard.classList.remove('hidden');
        msgEl.textContent = objetivo === 'sede'
            ? 'Paso 1: Apunte la cámara al QR Fijo de la Sede Donsen...'
            : 'Paso 2: Apunte la cámara al Carnet QR del Empleado...';

        // Activar aceleración nativa de lectura de códigos en Android/Chrome
        html5Qr = new Html5Qrcode('qr-reader', {
            experimentalFeatures: { useBarCodeDetectorIfSupported: true },
            verbose: false
        });

        const qrboxFunc = (viewfinderWidth, viewfinderHeight) => {
            const minEdge = Math.min(viewfinderWidth, viewfinderHeight);
            const size = Math.max(180, Math.floor(minEdge * 0.75));
            return { width: size, height: size };
        };

        try {
            await html5Qr.start(
                { facingMode: 'environment' },
                { fps: 15, qrbox: qrboxFunc },
                (texto) => {
                    detenerCamara();
                    if (objetivo === 'sede') {
                        validarSede(texto);
                    } else {
                        enviarMarcajeAjax(texto, false);
                    }
                },
                () => {}
            );
        } catch (e) {
            detenerCamara();
            const errBox = document.getElementById('paso-1-error');
            errBox.classList.remove('hidden');
            errBox.textContent = 'No se pudo abrir la cámara en el navegador. Verifique los permisos o escanee el QR de la entrada directamente con la cámara normal de su teléfono.';
        }
    }

    async function detenerCamara() {
        document.getElementById('visor-camara-card').classList.add('hidden');
        if (html5Qr) {
            try { await html5Qr.stop(); html5Qr.clear(); } catch (e) {}
            html5Qr = null;
        }
    }

    async function enviarMarcajeAjax(identificador, modoPruebaAdmin = false) {
        if (!identificador || !identificador.trim()) {
            return;
        }

        const res = await fetch('{{ route("api.marcar") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                qr_sede: qrSedeValidado,
                identificador: identificador.trim(),
                device_token: obtenerDeviceToken(),
                device_info: navigator.platform || 'Smartphone',
                latitud: gpsCoords.lat,
                longitud: gpsCoords.lng,
                modo_prueba_admin: modoPruebaAdmin
            })
        });

        const data = await res.json();
        const box = document.getElementById('resultado-marcaje-box');
        box.classList.remove('hidden');
        box.className = 'bg-white p-6 rounded-2xl border-2 shadow-md space-y-2 ' + (data.ok ? 'border-[#197328]' : 'border-rose-600');
        box.innerHTML = `
            <div class="text-xs font-extrabold uppercase ${data.ok ? 'text-[#197328]' : 'text-rose-600'}">${data.badge || data.titulo}</div>
            <div class="text-lg font-extrabold text-[#373A39]">${data.empleado || ''}</div>
            <div class="text-xs text-gray-500">${data.cargo || ''}</div>
            ${data.hora ? `<div class="text-sm font-bold text-[#373A39]">Hora: ${data.hora} • Estatus: ${data.estatus || ''}</div>` : ''}
            <p class="text-xs text-gray-600 pt-1">${data.mensaje}</p>
            ${data.mapa_url ? `<a href="${data.mapa_url}" target="_blank" class="inline-block text-xs font-bold text-blue-600 hover:underline pt-1"><i class="fa-solid fa-map-pin mr-1"></i>Ver ubicación exacta en Google Maps</a>` : ''}
        `;
    }
</script>
@endsection
LARAVEL_FILE,

    'resources/views/reportes/index.blade.php' => <<<'LARAVEL_FILE'
@extends('layouts.app')
@section('title', 'Consultas y Reportes | Donsen®')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-extrabold text-[#373A39]">Consultas y Reportes Gerenciales</h1>
            <p class="text-sm text-gray-500">Auditoría de asistencia y exportación para cálculo de nómina</p>
        </div>
        <div class="flex gap-2.5">
            <a href="{{ route('reportes.csv', request()->query()) }}" class="px-4 py-2.5 rounded-xl bg-[#197328] text-white text-xs font-bold">
                <i class="fa-solid fa-file-csv mr-1.5"></i>Exportar Excel (CSV)
            </a>
            <a href="{{ route('reportes.pdf', request()->query()) }}" target="_blank" class="px-4 py-2.5 rounded-xl bg-[#373A39] text-white text-xs font-bold">
                <i class="fa-solid fa-file-pdf mr-1.5"></i>Vista PDF / Imprimir
            </a>
        </div>
    </div>

    <form method="GET" action="{{ route('reportes.index') }}" class="bg-white p-5 rounded-2xl border border-gray-200 grid grid-cols-1 sm:grid-cols-5 gap-3">
        <input type="date" name="desde" value="{{ $desde }}" class="px-3 py-2 rounded-xl border text-xs">
        <input type="date" name="hasta" value="{{ $hasta }}" class="px-3 py-2 rounded-xl border text-xs">
        <select name="departamento_id" class="px-3 py-2 rounded-xl border text-xs bg-white">
            <option value="">Todos los Departamentos</option>
            @foreach($departamentos as $d)
                <option value="{{ $d->id }}" {{ request('departamento_id') == $d->id ? 'selected' : '' }}>{{ $d->nombre_departamento }}</option>
            @endforeach
        </select>
        <select name="estatus" class="px-3 py-2 rounded-xl border text-xs bg-white">
            <option value="">Todos los Estatus</option>
            <option value="A tiempo" {{ request('estatus') == 'A tiempo' ? 'selected' : '' }}>A tiempo</option>
            <option value="Retardo" {{ request('estatus') == 'Retardo' ? 'selected' : '' }}>Retardo</option>
            <option value="Inasistencia" {{ request('estatus') == 'Inasistencia' ? 'selected' : '' }}>Inasistencia</option>
        </select>
        <button type="submit" class="py-2 px-4 rounded-xl bg-[#197328] text-white text-xs font-bold">Filtrar Resultados</button>
    </form>

    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-[#F4F6F5] text-gray-600 text-xs uppercase border-b border-gray-200">
                        <th class="py-3 px-4">Fecha</th>
                        <th class="py-3 px-4">Cédula</th>
                        <th class="py-3 px-4">Empleado</th>
                        <th class="py-3 px-4">Departamento</th>
                        <th class="py-3 px-4">Entrada</th>
                        <th class="py-3 px-4">Salida</th>
                        <th class="py-3 px-4">Horas Lab.</th>
                        <th class="py-3 px-4">Ubicación GPS</th>
                        <th class="py-3 px-4">Estatus</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-xs sm:text-sm">
                    @forelse($registros as $r)
                    <tr>
                        <td class="py-3 px-4">{{ \Carbon\Carbon::parse($r->fecha)->format('d/m/Y') }}</td>
                        <td class="py-3 px-4 font-mono text-xs">{{ $r->empleado->cedula }}</td>
                        <td class="py-3 px-4 font-bold">{{ $r->empleado->nombres_apellidos }}</td>
                        <td class="py-3 px-4">{{ $r->empleado->departamento->nombre_departamento }}</td>
                        <td class="py-3 px-4 font-semibold">{{ $r->hora_entrada ? substr($r->hora_entrada, 0, 5) : '--:--' }}</td>
                        <td class="py-3 px-4 font-semibold">{{ $r->hora_salida ? substr($r->hora_salida, 0, 5) : '--:--' }}</td>
                        <td class="py-3 px-4">{{ $r->horas_laboradas }}</td>
                        <td class="py-3 px-4 text-xs">
                            @if($r->latitud_marcaje && $r->longitud_marcaje)
                                <a href="https://www.google.com/maps?q={{ $r->latitud_marcaje }},{{ $r->longitud_marcaje }}" target="_blank"
                                   class="inline-flex items-center gap-1 text-blue-600 hover:underline font-semibold">
                                    <i class="fa-solid fa-location-dot"></i>
                                    <span>{{ $r->distancia_metros !== null ? $r->distancia_metros . 'm' : 'Mapa' }}</span>
                                </a>
                            @else
                                <span class="text-gray-400">N/A</span>
                            @endif
                        </td>
                        <td class="py-3 px-4 font-bold">{{ $r->estatus }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="py-8 text-center text-gray-400">No se encontraron registros para el rango seleccionado.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
LARAVEL_FILE,

    'resources/views/reportes/pdf.blade.php' => <<<'LARAVEL_FILE'
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Oficial de Asistencia - Donsen®</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="p-8 bg-white text-[#373A39]" onload="window.print()">
    <div class="flex items-center justify-between border-b-2 border-[#197328] pb-4 mb-6">
        <div class="flex items-center gap-4">
            <x-donsen-logo :width="150" />
            <div>
                <h1 class="text-lg font-extrabold uppercase">Reporte Consolidado de Control de Asistencia</h1>
                <p class="text-xs text-gray-500">Período: {{ \Carbon\Carbon::parse($desde)->format('d/m/Y') }} al {{ \Carbon\Carbon::parse($hasta)->format('d/m/Y') }} • Sede Principal Caracas</p>
            </div>
        </div>
        <div class="text-right text-xs text-gray-500">
            <div>Emisión: {{ now('America/Caracas')->format('d/m/Y H:i') }}</div>
            <div>BD: db_asistencia_donsen</div>
        </div>
    </div>

    <table class="w-full text-left border-collapse text-xs">
        <thead>
            <tr class="bg-gray-100 border-b border-gray-300 uppercase">
                <th class="py-2 px-3">Fecha</th>
                <th class="py-2 px-3">Cédula</th>
                <th class="py-2 px-3">Trabajador</th>
                <th class="py-2 px-3">Departamento</th>
                <th class="py-2 px-3">Entrada</th>
                <th class="py-2 px-3">Salida</th>
                <th class="py-2 px-3">Horas</th>
                <th class="py-2 px-3">Estatus</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-200">
            @foreach($registros as $r)
            <tr>
                <td class="py-2 px-3">{{ \Carbon\Carbon::parse($r->fecha)->format('d/m/Y') }}</td>
                <td class="py-2 px-3">{{ $r->empleado->cedula }}</td>
                <td class="py-2 px-3 font-bold">{{ $r->empleado->nombres_apellidos }}</td>
                <td class="py-2 px-3">{{ $r->empleado->departamento->nombre_departamento }}</td>
                <td class="py-2 px-3">{{ $r->hora_entrada ? substr($r->hora_entrada, 0, 5) : '--:--' }}</td>
                <td class="py-2 px-3">{{ $r->hora_salida ? substr($r->hora_salida, 0, 5) : '--:--' }}</td>
                <td class="py-2 px-3">{{ $r->horas_laboradas }}</td>
                <td class="py-2 px-3 font-bold">{{ $r->estatus }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
LARAVEL_FILE,
];

if (PHP_SAPI === 'cli') {
    echo "=================================================================\n";
    echo " INSTALADOR MVC LARAVEL - SISTEMA DE ASISTENCIA QR DONSEN (2026) \n";
    echo "=================================================================\n";

    foreach ($laravelProjectFiles as $relativePath => $content) {
        $directory = dirname($relativePath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            fwrite(STDERR, "[ERROR] No se pudo crear el directorio: {$directory}\n");
            exit(1);
        }

        if (file_put_contents($relativePath, $content) === false) {
            fwrite(STDERR, "[ERROR] No se pudo escribir el archivo: {$relativePath}\n");
            exit(1);
        }

        echo "[OK] Archivo generado: {$relativePath}\n";
    }

    echo "\nInstalación de archivos Laravel completada con éxito.\n";
    echo "=================================================================\n";
}
