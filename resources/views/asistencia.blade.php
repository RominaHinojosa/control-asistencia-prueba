<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registro de Asistencia · Estatuto Administrativo</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-800">
    <div class="mx-auto max-w-6xl px-4 py-10">
        <header class="mb-8">
            <h1 class="text-2xl font-bold text-slate-900">Módulo de Registro de Asistencia</h1>
            <p class="mt-1 text-sm text-slate-500">
                Turnos rotativos — Estatuto Administrativo (Ley 18.834, art. 66). Sube el PDF exportado del
                reloj control para calcular el descanso complementario según jornada programada vs. marcación real.
            </p>
        </header>

        {{-- Formulario de carga --}}
        <form id="form-asistencia" action="{{ route('asistencia.procesar') }}" method="POST" enctype="multipart/form-data"
              class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            @csrf

            <label for="archivo"
                   id="drop-zone"
                   class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed border-slate-300 bg-slate-50 px-6 py-12 text-center transition hover:border-indigo-400 hover:bg-indigo-50">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0 3 3m-3-3-3 3M6.75 19.5a4.5 4.5 0 0 1-1.41-8.775 5.25 5.25 0 0 1 10.233-2.33 3 3 0 0 1 3.758 3.848A3.752 3.752 0 0 1 18 19.5H6.75Z" />
                </svg>
                <span class="text-sm font-medium text-slate-700">
                    Arrastra el PDF aquí o <span class="text-indigo-600 underline">selecciónalo</span>
                </span>
                <span id="nombre-archivo" class="text-xs text-slate-400">Solo archivos PDF, máx. 10 MB</span>
                <input id="archivo" name="archivo" type="file" accept="application/pdf" class="hidden">
            </label>

            @error('archivo')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror

            <button type="submit"
                    class="mt-5 inline-flex w-full items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                Procesar marcaciones
            </button>
        </form>

        {{-- Mensaje de error de procesamiento --}}
        @if (! empty($error))
            <div class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ $error }}
            </div>
        @endif

        @if (! empty($filas))
            {{-- Encabezado del documento --}}
            <section class="mt-8 grid grid-cols-2 gap-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm sm:grid-cols-3 lg:grid-cols-5">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Funcionario</p>
                    <p class="mt-1 text-sm font-medium text-slate-900">{{ $encabezado['funcionario'] }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">RUT</p>
                    <p class="mt-1 text-sm font-medium text-slate-900">{{ $encabezado['rut'] }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Período</p>
                    <p class="mt-1 text-sm font-medium text-slate-900">{{ $encabezado['periodo'] }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Jornada</p>
                    <p class="mt-1 text-sm font-medium text-slate-900">{{ $encabezado['jornada'] }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Régimen</p>
                    <p class="mt-1 text-sm font-medium text-slate-900">{{ $encabezado['regimen'] }}</p>
                </div>
            </section>

            {{-- Resumen semanal: jornada ordinaria de 44 hrs. --}}
            <section class="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-lg font-semibold text-slate-900">Resumen semanal (jornada ordinaria: {{ $jornadaSemanal }} hrs.)</h2>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <th class="px-3 py-2">Semana ISO</th>
                                <th class="px-3 py-2 text-right">Hrs. trabajadas</th>
                                <th class="px-3 py-2 text-right">Diferencia vs. {{ $jornadaSemanal }} hrs.</th>
                                <th class="px-3 py-2 text-right">Extra 25%</th>
                                <th class="px-3 py-2 text-right">Extra 50%</th>
                                <th class="px-3 py-2 text-right">Descanso compensatorio</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($resumenSemanal as $semana)
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ $semana['semana'] }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right text-slate-600">{{ number_format($semana['horas_trabajadas'], 2) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-medium {{ $semana['diferencia_vs_44'] > 0 ? 'text-amber-700' : 'text-slate-400' }}">
                                        {{ $semana['diferencia_vs_44'] > 0 ? '+' : '' }}{{ number_format($semana['diferencia_vs_44'], 2) }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right text-slate-600">{{ number_format($semana['horas_extra_25'], 2) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right text-slate-600">{{ number_format($semana['horas_extra_50'], 2) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-semibold text-slate-900">{{ number_format($semana['horas_compensatorias'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            {{-- Detalle por turno --}}
            <section class="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="mb-1 text-lg font-semibold text-slate-900">Detalle por turno</h2>
                <p class="mb-4 text-xs text-slate-400">
                    "Reloj control" muestra lo informado por el propio reporte (si viene incluido); las columnas de
                    recargo se calculan de forma independiente comparando el horario programado con la marcación real.
                </p>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                                <th class="px-3 py-2">Fecha</th>
                                <th class="px-3 py-2">Turno</th>
                                <th class="px-3 py-2">Entrada Prog.</th>
                                <th class="px-3 py-2">Salida Prog.</th>
                                <th class="px-3 py-2">Marc. Entrada</th>
                                <th class="px-3 py-2">Marc. Salida</th>
                                <th class="px-3 py-2 text-right">Hrs. Trab.</th>
                                <th class="px-3 py-2 text-right">Extra 25%</th>
                                <th class="px-3 py-2 text-right">Extra 50%</th>
                                <th class="px-3 py-2 text-right">Descanso comp.</th>
                                <th class="px-3 py-2 text-right">Hrs. Trab. (reloj)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($filas as $fila)
                                <tr class="{{ $fila['horas_extra_25'] + $fila['horas_extra_50'] > 0 ? 'bg-amber-50' : '' }}">
                                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ $fila['fecha'] }}</td>
                                    <td class="whitespace-nowrap px-3 py-2">
                                        <span @class([
                                            'rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-slate-100 text-slate-600' => $fila['turno'] === 'Libre',
                                            'bg-sky-100 text-sky-700' => $fila['turno'] === 'Turno Largo de Día',
                                            'bg-indigo-100 text-indigo-700' => $fila['turno'] === 'Turno Noche',
                                            'bg-violet-100 text-violet-700' => $fila['turno'] === 'Saliente',
                                        ])>
                                            {{ $fila['turno'] }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ $fila['entrada_prog'] ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ $fila['salida_prog'] ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ $fila['marc_entrada'] ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-slate-600">{{ $fila['marc_salida'] ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right text-slate-600">{{ number_format($fila['horas_trabajadas'], 2) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right {{ $fila['horas_extra_25'] > 0 ? 'font-medium text-amber-700' : 'text-slate-400' }}">
                                        {{ number_format($fila['horas_extra_25'], 2) }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right {{ $fila['horas_extra_50'] > 0 ? 'font-medium text-rose-700' : 'text-slate-400' }}">
                                        {{ number_format($fila['horas_extra_50'], 2) }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-semibold text-slate-900">{{ number_format($fila['horas_compensatorias'], 2) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right text-slate-500">
                                        {{ $fila['hrs_trab_reloj'] !== null ? number_format($fila['hrs_trab_reloj'], 2) : '—' }}
                                        @if ($fila['discrepancia'])
                                            <span class="ml-1 rounded-full bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700" title="Difiere de lo calculado por el sistema">⚠</span>
                                        @endif
                                    </td>
                                </tr>
                                @if ($fila['sin_programacion'])
                                    <tr>
                                        <td colspan="11" class="px-3 pb-2 text-xs text-amber-600">
                                            Sin horario programado informado para este turno: no fue posible calcular sobretiempo, solo horas trabajadas.
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>

    <script>
        const input = document.getElementById('archivo');
        const dropZone = document.getElementById('drop-zone');
        const nombreArchivo = document.getElementById('nombre-archivo');

        const mostrarNombre = (file) => {
            nombreArchivo.textContent = file ? file.name : 'Solo archivos PDF, máx. 10 MB';
        };

        input.addEventListener('change', () => mostrarNombre(input.files[0]));

        ['dragover', 'dragleave', 'drop'].forEach((evento) => {
            dropZone.addEventListener(evento, (e) => e.preventDefault());
        });

        dropZone.addEventListener('dragover', () => {
            dropZone.classList.add('border-indigo-400', 'bg-indigo-50');
        });

        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('border-indigo-400', 'bg-indigo-50');
        });

        dropZone.addEventListener('drop', (e) => {
            dropZone.classList.remove('border-indigo-400', 'bg-indigo-50');
            const file = e.dataTransfer.files[0];
            if (file) {
                input.files = e.dataTransfer.files;
                mostrarNombre(file);
            }
        });
    </script>
</body>
</html>
