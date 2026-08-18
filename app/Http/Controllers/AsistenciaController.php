<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Procesa reportes en PDF del reloj control y aplica las reglas de
 * compensación de horas del Estatuto Administrativo (Ley 18.834, art. 66)
 * para funcionarios con turnos rotativos (Turno Largo de Día, Turno Noche,
 * Saliente y Libre).
 *
 * Reglas aplicadas:
 *   - Jornada ordinaria: 44 horas semanales, distribuidas según el rol de
 *     turnos (la jornada diaria efectiva la define cada fila mediante las
 *     columnas "Entrada Prog." / "Salida Prog.").
 *   - Recargo 25%: tramo de sobretiempo que cae entre las 07:00 y las
 *     21:00 de lunes a viernes.
 *   - Recargo 50%: tramo de sobretiempo que cae entre las 21:00 y las
 *     07:00, o en sábado, domingo o feriado.
 *   - La compensación se expresa por defecto como descanso complementario
 *     (horas de tiempo compensatorio), no como pago monetario.
 */
class AsistenciaController extends Controller
{
    /** Jornada ordinaria semanal según el Estatuto Administrativo (art. 66). */
    private const JORNADA_SEMANAL_HORAS = 44;

    /** Ventana horaria diurna hábil (lunes a viernes) para el recargo del 25%. */
    private const HORA_INICIO_DIURNO = 7;
    private const HORA_FIN_DIURNO = 21;

    private const RECARGO_DIURNO = 0.25;
    private const RECARGO_NOCTURNO_FESTIVO = 0.50;

    /**
     * Feriados fijos (formato día-mes) mantenidos a mano para esta prueba.
     * Los feriados de fecha variable (Semana Santa, elecciones, etc.) deben
     * agregarse manualmente cada año.
     */
    private const FERIADOS_FIJOS = [
        '1-1', '1-5', '21-5', '7-6', '20-6', '29-6', '16-7',
        '15-8', '18-9', '19-9', '20-9', '12-10', '31-10', '1-11', '8-12', '25-12',
    ];

    public function index(): View
    {
        return view('asistencia');
    }

    public function procesar(Request $request): View
    {
        $request->validate([
            'archivo' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        try {
            $texto = $this->extraerTexto($request->file('archivo'));
        } catch (Throwable $e) {
            return view('asistencia', [
                'error' => 'No fue posible leer el PDF. Verifica que no esté escaneado como imagen ni protegido con contraseña. Detalle: '.$e->getMessage(),
            ]);
        }

        $lineas = preg_split('/\r\n|\r|\n/', $texto);
        $encabezado = $this->parsearEncabezado($lineas);
        $filasCrudas = $this->parsearFilas($texto);

        if (empty($filasCrudas)) {
            return view('asistencia', [
                'error' => 'No se encontraron filas con el formato de tabla esperado (Fecha, Tipo Turno, Entrada Prog., Salida Prog., Marcación Entrada, Marcación Salida...). Revisa el patrón de extracción según el formato real de tu reloj control.',
            ]);
        }

        $filas = array_map(fn (array $fila) => $this->calcularFila($fila), $filasCrudas);

        return view('asistencia', [
            'encabezado' => $encabezado,
            'filas' => $filas,
            'resumenSemanal' => $this->calcularResumenSemanal($filas),
            'jornadaSemanal' => self::JORNADA_SEMANAL_HORAS,
        ]);
    }

    private function extraerTexto(UploadedFile $archivo): string
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($archivo->getRealPath());

        return $this->normalizarUtf8($pdf->getText());
    }

    /**
     * Garantiza que el texto quede en UTF-8 válido sin doble codificación.
     * Si el texto ya es UTF-8 (caso normal con pdfparser) se deja intacto;
     * de lo contrario se asume que el PDF entregó Windows-1252/ISO-8859-1.
     * Aplicar utf8_encode() sin esta verificación es lo que produce
     * mojibake (un texto ya válido en UTF-8 vuelve a codificarse como si
     * fuera Latin-1, duplicando los caracteres acentuados).
     */
    private function normalizarUtf8(string $texto): string
    {
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);

        if (mb_check_encoding($texto, 'UTF-8')) {
            return $texto;
        }

        return mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    }

    /**
     * Extrae los datos de encabezado del documento: Funcionario, RUT,
     * Período, Jornada y Régimen. El reloj control suele concatenar más de
     * un campo en la misma línea (p.ej. "Carlos Andrés Morales Sepúlveda
     * RUT: 15.482.910-3" o "01/08/2026 al 15/08/2026 Jornada: Sistema de
     * turnos rotativos"), así que cada campo se acota hasta el punto donde
     * empieza la siguiente etiqueta conocida, no solo hasta el fin de línea.
     *
     * @return array{funcionario: string, rut: string, periodo: string, jornada: string, regimen: string}
     */
    private function parsearEncabezado(array $lineas): array
    {
        // Alternativa de todas las etiquetas reconocidas, usada como "límite" para
        // saber dónde termina el valor de un campo y empieza el siguiente.
        $limite = '(?:Funcionario|RUT|Per[ií]odo|Jornada|R[eé]gimen)';

        $funcionario = $this->extraerCampoEncabezado($lineas, 'Funcionario', $limite)
            ?? $this->extraerNombreAntesDeRut($lineas)
            ?? 'No detectado';

        return [
            'funcionario' => $funcionario,
            'rut' => $this->extraerRut($lineas) ?? 'No detectado',
            'periodo' => $this->extraerCampoEncabezado($lineas, 'Per[ií]odo', $limite) ?? 'No detectado',
            'jornada' => $this->extraerCampoEncabezado($lineas, 'Jornada', $limite) ?? 'No detectado',
            'regimen' => $this->extraerCampoEncabezado($lineas, 'R[eé]gimen', $limite) ?? 'No detectado',
        ];
    }

    /**
     * Busca la etiqueta $patronEtiqueta en las líneas del encabezado y
     * devuelve su valor, recortado justo antes de que empiece cualquier
     * otra etiqueta ($patronLimite) que venga pegada en la misma línea.
     * Si la etiqueta está sola en su línea, el valor se busca en la(s)
     * línea(s) siguiente(s) con el mismo recorte.
     */
    private function extraerCampoEncabezado(array $lineas, string $patronEtiqueta, string $patronLimite): ?string
    {
        foreach ($lineas as $indice => $linea) {
            $lineaLimpia = trim($linea);

            if ($lineaLimpia === '' || ! preg_match('/'.$patronEtiqueta.'/iu', $lineaLimpia)) {
                continue;
            }

            $valor = $this->capturarHastaSiguienteEtiqueta($lineaLimpia, $patronEtiqueta.'\s*:?\s*', $patronLimite);

            if ($valor !== null && $valor !== '') {
                return $valor;
            }

            for ($siguiente = $indice + 1; $siguiente < count($lineas); $siguiente++) {
                $lineaSiguiente = trim($lineas[$siguiente]);

                if ($lineaSiguiente === '') {
                    continue;
                }

                // La etiqueta puede haber quedado sola en su línea y el valor real
                // llegar pegado con la SIGUIENTE etiqueta en la línea de abajo
                // (p.ej. "01/08/2026 al 15/08/2026 Jornada: ..."): se recorta igual.
                return $this->capturarHastaSiguienteEtiqueta($lineaSiguiente, '', $patronLimite) ?: $lineaSiguiente;
            }
        }

        return null;
    }

    private function capturarHastaSiguienteEtiqueta(string $linea, string $prefijo, string $patronLimite): ?string
    {
        $patron = '/'.$prefijo.'(?<valor>.*?)(?=\s*'.$patronLimite.'\s*:?|$)/iu';

        if (! preg_match($patron, $linea, $m)) {
            return null;
        }

        return trim($m['valor'], " \t:-");
    }

    /**
     * Algunos reportes no rotulan el nombre con "Funcionario:" y lo dejan
     * suelto justo antes de la etiqueta "RUT" en la misma línea
     * (p.ej. "Carlos Andrés Morales Sepúlveda RUT: 15.482.910-3").
     */
    private function extraerNombreAntesDeRut(array $lineas): ?string
    {
        foreach ($lineas as $linea) {
            if (preg_match('/^(?<nombre>.+?)\s+RUT\s*:?/iu', trim($linea), $m)) {
                $nombre = trim($m['nombre']);

                if ($nombre !== '') {
                    return $nombre;
                }
            }
        }

        return null;
    }

    /** El formato del RUT es autodescriptivo, así que se busca directamente sin depender de la etiqueta "RUT". */
    private function extraerRut(array $lineas): ?string
    {
        foreach ($lineas as $linea) {
            if (preg_match('/(?<rut>\d{1,2}\.?\d{3}\.?\d{3}-[\dkK])/u', $linea, $m)) {
                return $m['rut'];
            }
        }

        return null;
    }

    /**
     * Extrae las filas de la tabla mediante un escaneo por tokens (no por
     * líneas), ya que pdfparser suele romper cada celda en su propia
     * línea. Reconstruye cada fila siguiendo el orden de columnas descrito:
     *   Fecha | Tipo Turno | Entrada Prog. | Salida Prog. |
     *   Marcación Entrada | Marcación Salida | Hrs Trab. | Recargo 25% | Recargo 50%
     * El tipo de turno puede venir como frase completa ("Turno Largo de
     * Día") o abreviado ("Largo", "Noche"), a veces seguido de un código
     * entre paréntesis en la misma línea o en la siguiente ("Largo\n(L)").
     * Las celdas sin dato se marcan con un guion ("-") en vez de quedar en
     * blanco, así que se consumen como "sin valor" en lugar de bloquear el
     * resto de la fila. Las tres últimas columnas (reportadas por el propio
     * reloj control) son opcionales: si no vienen, el sistema igual calcula
     * sus propios valores en calcularFila().
     *
     * @return array<int, array<string, mixed>>
     */
    private function parsearFilas(string $texto): array
    {
        $tokens = preg_split('/\s+/u', trim($texto));
        $tokensNormalizados = array_map(fn (string $t) => $this->normalizarClave($t), $tokens);

        $fechaPattern = '/^\d{4}[\/-]\d{1,2}[\/-]\d{1,2}$|^\d{1,2}[\/-]\d{1,2}[\/-]\d{4}$/';
        $horaPattern = '/^\d{1,2}:\d{2}$/';
        $codigoTurnoPattern = '/^\(?[A-ZÑ]{1,4}\)?$/iu';

        $filas = [];
        $total = count($tokens);
        $i = 0;

        while ($i < $total) {
            if (! preg_match($fechaPattern, $tokens[$i])) {
                $i++;

                continue;
            }

            $fecha = $this->normalizarFecha($tokens[$i]);
            $i++;

            $coincidencia = $this->coincidenciaTurno($tokensNormalizados, $i);

            if ($coincidencia === null) {
                continue; // No hay un turno reconocible justo después de la fecha: se descarta y se sigue buscando.
            }

            [$turno, $consumidos] = $coincidencia;
            $i += $consumidos;

            // Código abreviado del turno (p.ej. "(L)", "(N)"): no aporta datos al cálculo, solo se descarta.
            if ($i < $total && preg_match($codigoTurnoPattern, $tokens[$i]) && ! preg_match($fechaPattern, $tokens[$i])) {
                $i++;
            }

            $fila = [
                'fecha' => $fecha,
                'turno' => $turno,
                'entrada_prog' => null,
                'salida_prog' => null,
                'marc_entrada' => null,
                'marc_salida' => null,
                'hrs_trab_reloj' => null,
                'recargo25_reloj' => null,
                'recargo50_reloj' => null,
            ];

            foreach (['entrada_prog', 'salida_prog', 'marc_entrada', 'marc_salida'] as $campo) {
                if ($i >= $total) {
                    break;
                }

                if ($this->esCeldaVacia($tokens[$i])) {
                    $i++; // Celda sin marcación ("-"): se descarta el marcador y se avanza.

                    continue;
                }

                if (preg_match($horaPattern, $tokens[$i])) {
                    $fila[$campo] = $this->normalizarHora($tokens[$i]);
                    $i++;
                }
            }

            foreach (['hrs_trab_reloj', 'recargo25_reloj', 'recargo50_reloj'] as $campo) {
                if ($i >= $total) {
                    break;
                }

                if ($this->esCeldaVacia($tokens[$i])) {
                    $i++;

                    continue;
                }

                $valor = $this->leerValorHoras($tokens[$i]);

                if ($valor !== null) {
                    $fila[$campo] = $valor;
                    $i++;
                }
            }

            $filas[] = $fila;
        }

        return $filas;
    }

    /**
     * Intenta reconocer, a partir de la posición $pos, uno de los turnos
     * del rol rotativo, ya sea como frase completa ("Turno Largo de Día")
     * o en su forma abreviada habitual en el reloj control ("Largo",
     * "Noche"). Se prueban primero las secuencias más largas para que una
     * frase completa no se confunda con una coincidencia parcial.
     *
     * @return array{0: string, 1: int}|null [etiqueta legible, tokens consumidos]
     */
    private function coincidenciaTurno(array $tokensNormalizados, int $pos): ?array
    {
        $candidatos = [
            [['TURNO', 'LARGO', 'DE', 'DIA'], 'Turno Largo de Día'],
            [['TURNO', 'LARGO', 'DIA'], 'Turno Largo de Día'],
            [['LARGO', 'DE', 'DIA'], 'Turno Largo de Día'],
            [['TURNO', 'LARGO'], 'Turno Largo de Día'],
            [['LARGO', 'DIA'], 'Turno Largo de Día'],
            [['TURNO', 'NOCHE'], 'Turno Noche'],
            [['LARGO'], 'Turno Largo de Día'],
            [['NOCHE'], 'Turno Noche'],
            [['SALIENTE'], 'Saliente'],
            [['LIBRE'], 'Libre'],
        ];

        foreach ($candidatos as [$secuencia, $etiqueta]) {
            $largo = count($secuencia);

            if (array_slice($tokensNormalizados, $pos, $largo) === $secuencia) {
                return [$etiqueta, $largo];
            }
        }

        return null;
    }

    /** Marcador de celda sin dato ("-", "--", "—") usado por el reloj control cuando no hay marcación. */
    private function esCeldaVacia(string $token): bool
    {
        return (bool) preg_match('/^[-–—]{1,3}$/u', $token);
    }

    /**
     * Aplica las reglas de compensación del art. 66 a una fila ya parseada:
     *   - Recargo 50%: TODO tramo trabajado (sea de la jornada programada o
     *     sobretiempo) que caiga entre las 21:00 y las 07:00, o en sábado,
     *     domingo o feriado — el recargo nocturno/festivo se paga por el
     *     solo hecho de cumplir el turno en esa ventana.
     *   - Recargo 25%: solo el tramo que exceda la jornada programada
     *     (antes de la entrada o después de la salida programada) y que
     *     caiga en horario diurno hábil (07:00 a 21:00, lunes a viernes).
     *     Sin horario programado no es posible determinar qué es
     *     sobretiempo diurno, por lo que ese tramo no genera recargo 25%.
     */
    private function calcularFila(array $fila): array
    {
        $fechaCarbon = Carbon::createFromFormat('d/m/Y', $fila['fecha'])->startOfDay();

        $entradaProg = $this->anclarHora($fechaCarbon, $fila['entrada_prog']);
        $salidaProg = $this->anclarHora($fechaCarbon, $fila['salida_prog']);
        $marcEntrada = $this->anclarHora($fechaCarbon, $fila['marc_entrada']);
        $marcSalida = $this->anclarHora($fechaCarbon, $fila['marc_salida']);

        if ($entradaProg && $salidaProg && $salidaProg->lessThanOrEqualTo($entradaProg)) {
            $salidaProg->addDay();
        }

        if ($marcEntrada && $marcSalida && $marcSalida->lessThanOrEqualTo($marcEntrada)) {
            $marcSalida->addDay();
        }

        $horasTrabajadas = ($marcEntrada && $marcSalida)
            ? round($marcEntrada->diffInMinutes($marcSalida) / 60, 2)
            : 0.0;

        $sinProgramacion = ($marcEntrada && $marcSalida) && ! ($entradaProg && $salidaProg);

        [$horasExtra25, $horasExtra50] = ($marcEntrada && $marcSalida)
            ? $this->calcularRecargos($marcEntrada, $marcSalida, $entradaProg, $salidaProg)
            : [0.0, 0.0];

        $horasExtra25 = round($horasExtra25, 2);
        $horasExtra50 = round($horasExtra50, 2);
        $horasCompensatorias = round($horasExtra25 * (1 + self::RECARGO_DIURNO) + $horasExtra50 * (1 + self::RECARGO_NOCTURNO_FESTIVO), 2);

        return [
            'fecha' => $fechaCarbon->format('d-m-Y'),
            'semana_iso' => $fechaCarbon->format('o-\WW'),
            'turno' => $fila['turno'],
            'entrada_prog' => $fila['entrada_prog'],
            'salida_prog' => $fila['salida_prog'],
            'marc_entrada' => $fila['marc_entrada'],
            'marc_salida' => $fila['marc_salida'],
            'horas_trabajadas' => $horasTrabajadas,
            'horas_extra_25' => $horasExtra25,
            'horas_extra_50' => $horasExtra50,
            'horas_compensatorias' => $horasCompensatorias,
            'hrs_trab_reloj' => $fila['hrs_trab_reloj'],
            'recargo25_reloj' => $fila['recargo25_reloj'],
            'recargo50_reloj' => $fila['recargo50_reloj'],
            'sin_programacion' => $sinProgramacion,
            'discrepancia' => $fila['hrs_trab_reloj'] !== null && abs($fila['hrs_trab_reloj'] - $horasTrabajadas) > 0.1,
        ];
    }

    private function anclarHora(Carbon $fecha, ?string $horaHHMM): ?Carbon
    {
        if ($horaHHMM === null) {
            return null;
        }

        [$horas, $minutos] = explode(':', $horaHHMM);

        return $fecha->copy()->setTime((int) $horas, (int) $minutos, 0);
    }

    /**
     * Recorre en bloques de 15 minutos todo el tramo efectivamente
     * trabajado (marc. entrada → marc. salida) y clasifica cada bloque:
     *   - Fuera de horario diurno hábil (21:00-07:00, sábado, domingo o
     *     feriado): recargo 50%, se cumpla o no dentro del turno programado.
     *   - Dentro de horario diurno hábil pero fuera del rango programado
     *     (entrada/salida prog.): recargo 25% (sobretiempo diurno).
     *   - Dentro de horario diurno hábil y dentro del rango programado:
     *     jornada ordinaria, sin recargo.
     *
     * @return array{0: float, 1: float} [horas con recargo 25%, horas con recargo 50%]
     */
    private function calcularRecargos(Carbon $marcEntrada, Carbon $marcSalida, ?Carbon $entradaProg, ?Carbon $salidaProg): array
    {
        $horas25 = 0.0;
        $horas50 = 0.0;
        $cursor = $marcEntrada->copy();

        while ($cursor->lessThan($marcSalida)) {
            $siguiente = $cursor->copy()->addMinutes(15);
            $limite = $siguiente->greaterThan($marcSalida) ? $marcSalida : $siguiente;
            $horas = $cursor->diffInMinutes($limite) / 60;

            if (! $this->esDiurnoHabil($cursor)) {
                $horas50 += $horas;
            } elseif ($entradaProg && $salidaProg) {
                $dentroDeProgramado = $cursor->greaterThanOrEqualTo($entradaProg) && $cursor->lessThan($salidaProg);

                if (! $dentroDeProgramado) {
                    $horas25 += $horas;
                }
            }

            $cursor = $limite;
        }

        return [$horas25, $horas50];
    }

    private function esDiurnoHabil(Carbon $momento): bool
    {
        $hora = (int) $momento->format('H');

        return $momento->isWeekday()
            && $hora >= self::HORA_INICIO_DIURNO
            && $hora < self::HORA_FIN_DIURNO
            && ! $this->esFeriado($momento->copy()->startOfDay());
    }

    private function esFeriado(Carbon $fecha): bool
    {
        return in_array($fecha->format('j-n'), self::FERIADOS_FIJOS, true);
    }

    /**
     * Agrupa las filas ya calculadas por semana ISO y compara el total de
     * horas trabajadas contra la jornada ordinaria de 44 horas semanales.
     *
     * @return array<int, array<string, mixed>>
     */
    private function calcularResumenSemanal(array $filas): array
    {
        $resumen = [];

        foreach ($filas as $fila) {
            $clave = $fila['semana_iso'];

            if (! isset($resumen[$clave])) {
                $resumen[$clave] = [
                    'semana' => $clave,
                    'horas_trabajadas' => 0.0,
                    'horas_extra_25' => 0.0,
                    'horas_extra_50' => 0.0,
                    'horas_compensatorias' => 0.0,
                ];
            }

            $resumen[$clave]['horas_trabajadas'] += $fila['horas_trabajadas'];
            $resumen[$clave]['horas_extra_25'] += $fila['horas_extra_25'];
            $resumen[$clave]['horas_extra_50'] += $fila['horas_extra_50'];
            $resumen[$clave]['horas_compensatorias'] += $fila['horas_compensatorias'];
        }

        ksort($resumen);

        return array_map(function (array $semana) {
            $semana['horas_trabajadas'] = round($semana['horas_trabajadas'], 2);
            $semana['horas_extra_25'] = round($semana['horas_extra_25'], 2);
            $semana['horas_extra_50'] = round($semana['horas_extra_50'], 2);
            $semana['horas_compensatorias'] = round($semana['horas_compensatorias'], 2);
            $semana['diferencia_vs_44'] = round($semana['horas_trabajadas'] - self::JORNADA_SEMANAL_HORAS, 2);

            return $semana;
        }, array_values($resumen));
    }

    private function normalizarClave(string $texto): string
    {
        $texto = mb_strtoupper($texto, 'UTF-8');

        return strtr($texto, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        ]);
    }

    private function normalizarFecha(string $fecha): string
    {
        $fecha = str_replace('-', '/', $fecha);
        $partes = explode('/', $fecha);

        if (strlen($partes[0]) === 4) {
            [$anio, $mes, $dia] = $partes;
        } else {
            [$dia, $mes, $anio] = $partes;
        }

        return sprintf('%02d/%02d/%04d', (int) $dia, (int) $mes, (int) $anio);
    }

    private function normalizarHora(string $hora): string
    {
        [$horas, $minutos] = explode(':', $hora);

        return sprintf('%02d:%02d', (int) $horas, (int) $minutos);
    }

    /** Admite formato "HH:MM" o decimal ("8.5" / "8,5") para columnas informadas por el reloj control. */
    private function leerValorHoras(string $token): ?float
    {
        if (preg_match('/^\d{1,2}:\d{2}$/', $token)) {
            [$horas, $minutos] = explode(':', $token);

            return round(((int) $horas) + ((int) $minutos) / 60, 2);
        }

        if (preg_match('/^\d{1,3}([.,]\d{1,2})?$/', $token)) {
            return (float) str_replace(',', '.', $token);
        }

        return null;
    }
}
