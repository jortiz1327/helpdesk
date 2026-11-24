# Manuales de la IA: catálogo por área y cómo mantenerlos al día

La IA contesta con lo que dicen estos manuales. Si un manual está desfasado, la IA lo repite: por eso hay un proceso.

## Dónde está cada cosa

| Área | Dónde | Qué contiene |
|---|---|---|
| Hoteles (buffets) | `resources/ai-docs/hoteles/*.md` | Una guía por cada apartado de la aplicación de hoteles |
| Soporte: reglas y protocolos | `resources/ai-docs/soporte/*.md` | Derivación, verificación del cliente, protocolo de garantías, base de conocimiento |
| Portal del cliente (PDF) | `resources/manuals/04-cliente.pdf` (clave `cliente` de `config/manuals.php`) | La guía que ve el cliente |
| Farmacias (AdminFarm) | `resources/ai-docs/farmacias/*.md` | En espera de la API; hoy solo el stock en etiquetas |
| Tiendas y supermercados | `resources/ai-docs/tiendas/` | Sin manuales todavía |
| Cloud (etiquetas, ESLAdmin) | `resources/ai-docs/cloud/` | Sin manuales todavía |

El catálogo está declarado en `config('ai.areas')`: nombre, carpeta (o PDF), cada cuántos días hay que revisar y su estado.
Para **añadir un área**: nueva entrada en esa lista y una carpeta en `resources/ai-docs/`. Para **añadir un manual** a un área:
un `.md` en su carpeta (empieza con `# Título`).

## Proceso cuando cambia la aplicación

1. **Editar** el manual del área afectada (el `.md`), con los nombres de pantallas y botones tal como aparecen en la app.
2. **Desplegar** (commit y despliegue normal).
3. En **Ajustes › Asistente IA**, pulsar **Actualizar conocimiento**: la IA carga los manuales nuevos o cambiados y olvida los borrados.
4. Pulsar **Revisado hoy** en la tarjeta de esa área (**Manuales por área**). La fecha se guarda en la base de datos, no en el repositorio.

## Cómo se avisa

La tarjeta **Manuales por área** (Ajustes › Asistente IA) marca cada área:

- **Al día**: cargada en la IA y revisada dentro de su plazo.
- **Sin cargar en la memoria**: hay manuales nuevos, cambiados o borrados que la IA todavía no tiene → «Actualizar conocimiento».
- **Toca revisar**: pasó el plazo (`revision_dias`) desde la última revisión, o nunca se ha marcado.
- **Sin manuales**: el área aún no tiene documentación.

No se envían correos ni avisos: se ve al entrar en Ajustes.

## Reglas para escribir un manual

- Un tema por archivo, con título claro: la IA busca por título y por palabras.
- Sin datos personales de clientes, ni claves, ni correos de personas.
- Si algo lo hace el equipo y no el cliente, dilo explícito (p. ej. «el alta de etiquetas en Conectividad la hace solo el administrador»).
- No contradecir `resources/ai/prompt-reglas.md` (las reglas de decisión mandan; los manuales explican el cómo).
- Si el manual cambia una regla (qué se deriva, qué se explica), cambiar también las reglas y `/ia/flujo`.
