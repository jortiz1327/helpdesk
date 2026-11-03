/*
 * Lector propio de Code 128 (el código que llevan las etiquetas electrónicas), fila a fila.
 *
 * zxing es muy estricto con el ancho de cada barra, y en una foto de una etiqueta las
 * barras claras sobre fondo oscuro «brillan» (salen más anchas), las oscuras se comen el
 * blanco y las pequeñas quedan a 2 px por módulo: no lee nada aunque se vea bien. Aquí:
 *  - los bordes se sitúan con precisión de subpíxel (donde la fila cruza el umbral local);
 *  - cada carácter se mide de borde a borde (barra + espacio contiguos), que no cambia si
 *    todas las barras engordan o adelgazan igual;
 *  - si un carácter queda entre dos símbolos se prueban los dos, y solo vale la lectura
 *    que cuadra con el dígito de control; si hubo dudas, además tiene que salir igual en
 *    otra fila. Así no se inventa un código.
 * Prueba las dos polaridades (barras oscuras o claras) y los dos sentidos.
 */

// Anchos (barra, espacio, barra…) de los 107 símbolos; el 106 es el de parada (7 elementos).
const PAT = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112']
  .map((s) => [...s].map(Number))
const LIMITE = 0.3      // error máximo (en módulos, cuadrático medio) para aceptar un símbolo
const DUDA = 0.07       // si el segundo está a menos de esto del primero, se prueban los dos
const MAX_RAMAS = 16

/*
 * Cuánto se parecen unos anchos medidos a un patrón: se ajusta por mínimos cuadrados
 * ancho = u·módulos ± e (u, el tamaño del módulo; e, lo que engordan las barras y
 * adelgazan los espacios por la tinta, el brillo o el desenfoque). Devuelve el error
 * medio en módulos, o Infinity si el ajuste no tiene sentido.
 */
const AJ = PAT.map((p) => {
  let pp = 0, ps = 0
  p.forEach((v, j) => { pp += v * v; ps += j % 2 ? -v : v })
  return { p, pp, ps, n: p.length, ss: p.length }
})
function error(w, i, a) {
  let pw = 0, sw = 0, ww = 0
  for (let j = 0; j < a.n; j++) { const v = w[i + j]; pw += a.p[j] * v; sw += j % 2 ? -v : v; ww += v * v }
  const det = a.pp * a.ss - a.ps * a.ps
  const u = (pw * a.ss - a.ps * sw) / det, e = (a.pp * sw - a.ps * pw) / det
  if (!(u > 0) || Math.abs(e) > 0.75 * u) return Infinity
  const r = Math.max(0, ww - u * pw - e * sw)
  return Math.sqrt(r / a.n) / u
}

/* Los símbolos que encajan con 6 anchos medidos: [] , [s] o [s, alternativa]. */
function candidatos(w, i) {
  let s1 = -1, d1 = Infinity, s2 = -1, d2 = Infinity
  for (let s = 0; s < 106; s++) {
    const d = error(w, i, AJ[s])
    if (d < d1) { s2 = s1; d2 = d1; s1 = s; d1 = d } else if (d < d2) { s2 = s; d2 = d }
  }
  if (d1 > LIMITE) return []
  return d2 <= LIMITE && d2 - d1 < DUDA ? [s1, s2] : [s1]
}

// La parada va pegada al margen y suele salir peor; el dígito de control ya protege.
const esParada = (w, i) => i + 7 <= w.length && error(w, i, AJ[106]) <= LIMITE * 1.5

/* Símbolos (con el de control al final, sin parada) a texto, si cuadra el control. */
function texto(sims) {
  if (sims.length < 3) return null
  let suma = sims[0]
  for (let i = 1; i < sims.length - 1; i++) suma += i * sims[i]
  if (suma % 103 !== sims[sims.length - 1]) return null
  let set = sims[0] === 103 ? 'A' : sims[0] === 104 ? 'B' : 'C'
  let out = ''
  for (const v of sims.slice(1, -1)) {
    if (set === 'C') {
      if (v < 100) out += String(v).padStart(2, '0')
      else if (v === 100) set = 'B'
      else if (v === 101) set = 'A'
    } else if (v === 99) set = 'C'
    else if (v === 100 && set === 'A') set = 'B'
    else if (v === 101 && set === 'B') set = 'A'
    else if (v < 96) out += set === 'B' ? String.fromCharCode(v + 32) : String.fromCharCode(v < 64 ? v + 32 : v - 64)
  }
  return out || null
}

/* Intenta leer desde cada barra de `w` (anchos alternos, empezando por una barra). */
function leerAnchos(w, apunta) {
  for (let i = 0; i + 6 <= w.length; i += 2) {
    // Primero, rápido, ¿parece un inicio (A, B o C)? Solo entonces se mira a fondo.
    if (error(w, i, AJ[103]) > LIMITE && error(w, i, AJ[104]) > LIMITE && error(w, i, AJ[105]) > LIMITE) continue
    const ini = candidatos(w, i).filter((s) => s >= 103 && s <= 105)
    if (!ini.length) continue
    // Los caracteres hasta la parada, cada uno con sus posibles símbolos.
    const pos = [ini]
    let j = i + 6, fin = false
    while (j + 6 <= w.length && pos.length < 40) {
      if (esParada(w, j)) { fin = true; break }
      const c = candidatos(w, j)
      if (!c.length) break
      pos.push(c); j += 6
    }
    if (!fin || pos.length < 3) continue
    let ramas = 1
    for (const c of pos) ramas *= c.length
    if (ramas > MAX_RAMAS) continue
    // Todas las combinaciones (pocas); vale la que cuadra con el dígito de control.
    const combos = pos.reduce((acc, c) => acc.flatMap((a) => c.map((s) => [...a, s])), [[]])
    for (const sims of combos) { const t = texto(sims); if (t) apunta(t, ramas > 1) }
    i = j   // lo leído no se vuelve a probar desde dentro
  }
}

/* Bordes de una fila con precisión de subpíxel: [posiciones, ¿empieza oscuro?]. */
function bordes(fila, umbral, w) {
  const pos = []
  let oscuro = fila[0] < umbral[0]
  const primero = oscuro
  for (let x = 1; x < w; x++) {
    const o = fila[x] < umbral[x]
    if (o !== oscuro) {
      const a = fila[x - 1] - umbral[x - 1], b = fila[x] - umbral[x]
      pos.push(x - 1 + (a === b ? 0.5 : a / (a - b)))
      oscuro = o
    }
  }
  return [pos, primero]
}

/*
 * Lee los Code 128 de una imagen en escala de grises (`lum`, ancho `w`, alto `h`),
 * recorriendo filas cada `paso` px desde `desde`. Devuelve [{ t: texto, n: filas en que
 * salió, seguro: si alguna vez salió sin dudas }].
 */
export function leerCode128(lum, w, h, paso = 2, desde = 1) {
  const votos = new Map()   // texto → { filas: Set, seguro }
  const fila = new Float32Array(w)
  const umbral = new Float32Array(w)
  const pref = new Float64Array(w + 1)
  // Umbral local con ventanas de varios tamaños: estrecha para los códigos diminutos (y
  // fondos que cambian), ancha para los grandes de barras gordas, que juntas oscurecen la
  // media de la zona.
  const ventanas = [...new Set([5, 10, 20, Math.max(30, Math.round(w / 12))])]
  for (const ventana of ventanas)
  for (let y = Math.max(1, desde); y < h - 1; y += paso) {
    // La fila suavizada con sus vecinas (menos ruido de la foto).
    let mn = 255, mx = 0
    for (let x = 0; x < w; x++) {
      const v = (lum[(y - 1) * w + x] + 2 * lum[y * w + x] + lum[(y + 1) * w + x]) / 4
      fila[x] = v; pref[x + 1] = pref[x] + v
      if (v < mn) mn = v
      if (v > mx) mx = v
    }
    if (mx - mn < 35) continue
    // Umbral local (media de la zona), para que un reflejo o un fondo oscuro no lo tapen.
    for (let x = 0; x < w; x++) {
      const a = Math.max(0, x - ventana), b = Math.min(w, x + ventana + 1)
      umbral[x] = (pref[b] - pref[a]) / (b - a)
    }
    const [pos, primeroOscuro] = bordes(fila, umbral, w)
    if (pos.length < 30) continue
    const anchos = []
    for (let i = 1; i < pos.length; i++) anchos.push(pos[i] - pos[i - 1])
    // anchos[0] es del tramo que empieza en pos[0]: oscuro si la fila empezó claro.
    const empiezaOscuro = !primeroOscuro
    const apunta = (t, dudoso) => {
      const v = votos.get(t) || { filas: new Set(), seguro: false }
      v.filas.add(y); if (!dudoso) v.seguro = true
      votos.set(t, v)
    }
    for (const barraOscura of [true, false]) {
      const p = empiezaOscuro === barraOscura ? 0 : 1
      leerAnchos(anchos.slice(p), apunta)
      const rev = [...anchos].reverse()
      // Al revés, el primer tramo es el último: oscuro si (n-1) tiene la paridad de 0 con el inicio.
      const ultimoOscuro = (anchos.length - 1) % 2 === 0 ? empiezaOscuro : !empiezaOscuro
      leerAnchos(rev.slice(ultimoOscuro === barraOscura ? 0 : 1), apunta)
    }
  }
  return [...votos].filter(([, v]) => v.seguro || v.filas.size >= 2).map(([t, v]) => ({ t, n: v.filas.size, seguro: v.seguro }))
}
