# Sistema de Control de Asistencia y Compensación de Turnos

Módulo web desarrollado en **Laravel** para la carga, lectura y procesamiento automatizado de reportes de reloj control en formato PDF. El sistema está orientado al régimen de trabajadores bajo el **Estatuto Administrativo (Ley N° 18.834)** con sistemas de turnos rotativos.

---

## 📌 Características Principales

- **Carga de Reportes PDF:** Soporte para subir archivos de reloj control mediante selector o arrastrar y soltar (*drag & drop*).
- **Procesamiento y Extracción por Tokens:** Integración de la librería `smalot/pdfparser` para descomponer y reconocer tablas y encabezados de asistencia estructurados.
- **Detección Automática de Metadatos:** Identificación y aislamiento de Funcionario, RUT, Período, Régimen y Jornada laboral.
- **Clasificación de Turnos Rotativos:** Reconocimiento de turnos Largo (L), Noche (N), Saliente (S) y Libre (X).
- **Cálculo de Compensaciones y Recargos:**
  - **Recargo 25%:** Horas extraordinarias realizadas en horario diurno.
  - **Recargo 50%:** Horas extraordinarias o turnos nocturnos (21:00 a 07:00 hrs), fines de semana y festivos.
  - **Descanso Compensatorio:** Cálculo consolidado de tiempo a compensar.
- **Resumen Semanal y Detallado:** Visualización en tablas interactivas con diseño moderno basado en Tailwind CSS.

---

## 🛠️ Tecnologías Utilizadas

- **Backend:** PHP 8.3+, Laravel 13
- **Frontend:** Blade, Tailwind CSS, Vite
- **Procesamiento de PDF:** `smalot/pdfparser`
- **Entorno Local:** Laragon / Node.js & NPM

---

## 🚀 Requisitos Previos

Asegúrate de contar con el siguiente software instalado en tu entorno local:

- [PHP 8.2 o superior](https://www.php.net/) (con extensiones `zip`, `mbstring`, `fileinfo` habilitadas)
- [Composer](https://getcomposer.org/)
- [Node.js y NPM](https://nodejs.org/)
- [Laragon](https://laragon.org/) o servidor web local equivalente

---

## ⚙️ Instalación y Configuración

1. **Clonar el repositorio:**
   ```bash
   git clone [https://github.com/RominaHinojosa/control-asistencia-prueba.git](https://github.com/RominaHinojosa/control-asistencia-prueba.git)
   cd control-asistencia-prueba