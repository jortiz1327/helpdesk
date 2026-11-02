// Celda de CSV (separador «;») que Excel no puede ejecutar como fórmula. Un texto que
// empieza por = + - @, tabulador o retorno se toma como fórmula aunque vaya entre
// comillas, y hay textos que vienen de fuera (nombres de tienda o de producto en ZKONG,
// clientes del portal): se les antepone un apóstrofo. Los números (también negativos)
// se dejan como están.
export const csvCell = (v) => {
  let s = String(v ?? '')
  if (typeof v !== 'number' && /^[=+\-@\t\r]/.test(s)) s = "'" + s
  return `"${s.replace(/"/g, '""')}"`
}
