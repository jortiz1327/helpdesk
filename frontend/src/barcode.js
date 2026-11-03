/*
 * Leer el código de barras de una foto (la frontal de una etiqueta, la trasera de una
 * antena). Las etiquetas llevan un Code 128 con su código (9 letras o números), a veces
 * diminuto, a veces en blanco sobre negro y casi nunca recto: lo lee primero nuestro
 * lector de Code 128 (code128.js, tolerante con esas fotos); si no, el del navegador
 * (BarcodeDetector: Android, Mac…) y zxing, que se carga solo cuando hace falta.
 *
 * Devuelve los códigos que parecen de etiqueta (9 letras o números) o de antena (MAC de
 * 12), sin repetir, el más claro primero; vacío si no lee ninguno. Un QR (la web del
 * producto, por ejemplo) o el código del producto no cuentan.
 */
import { leerCode128 } from './code128.js'

const ESL = /^[A-Z0-9]{9}$/
const AP = /^[0-9A-F]{12}$/

const limpiar = (txt) => String(txt || '').toUpperCase().replace(/[\s:\-]/g, '')
const valido = (c) => ESL.test(c) || AP.test(c)
const respiro = () => new Promise((r) => setTimeout(r, 0))   // que la pantalla no se congele

/* La foto ya decodificada. createImageBitmap respeta la orientación EXIF del móvil y no
   depende de que la página se esté pintando (img.decode() se queda colgado en una
   pestaña en segundo plano); si no lo hay, un <img> esperando a su onload. */
async function imagen(file) {
  if ('createImageBitmap' in window) {
    try { return await createImageBitmap(file, { imageOrientation: 'from-image' }) } catch { /* formato raro: <img> */ }
  }
  const url = URL.createObjectURL(file)
  try {
    const img = new Image()
    await new Promise((ok, ko) => { img.onload = ok; img.onerror = ko; img.src = url })
    return img
  } finally { setTimeout(() => URL.revokeObjectURL(url), 0) }
}
const ancho = (img) => img.naturalWidth || img.width
const alto = (img) => img.naturalHeight || img.height

/* La foto en un canvas, como mucho de `lado` px y girada `giro` grados. */
function lienzo(img, lado, giro) {
  const k = Math.min(1, lado / Math.max(ancho(img), alto(img)))
  const w = Math.round(ancho(img) * k), h = Math.round(alto(img) * k)
  const c = document.createElement('canvas')
  const recto = giro % 180 === 0
  c.width = recto ? w : h; c.height = recto ? h : w
  const g = c.getContext('2d', { willReadFrequently: true })
  g.translate(c.width / 2, c.height / 2); g.rotate((giro * Math.PI) / 180)
  g.drawImage(img, -w / 2, -h / 2, w, h)
  return c
}

/* El canvas en escala de grises: [luminancia, ancho, alto]. */
function grises(c) {
  const { data, width, height } = c.getContext('2d').getImageData(0, 0, c.width, c.height)
  const lum = new Uint8ClampedArray(width * height)
  for (let i = 0, j = 0; i < data.length; i += 4, j++) lum[j] = (data[i] * 299 + data[i + 1] * 587 + data[i + 2] * 114) / 1000
  return [lum, width, height]
}

/*
 * Nuestro lector, en horizontal y en vertical (los dos sentidos se prueban dentro).
 * Primero una fila de cada dos; si no sale nada, las otras. Si hay alguna lectura sin
 * dudas, las dudosas se descartan (así no se cuela un código parecido mal leído).
 */
async function conCode128(img) {
  const lados = [0, 90].map((g) => grises(lienzo(img, 2400, g)))
  const halla = new Map()
  for (const desde of [1, 2]) {
    for (const [lum, w, h] of lados) {
      await respiro()
      for (const r of leerCode128(lum, w, h, 2, desde)) {
        const c = limpiar(r.t)
        if (!valido(c)) continue
        const v = halla.get(c) || { n: 0, seguro: false }
        v.n += r.n; v.seguro ||= r.seguro
        halla.set(c, v)
      }
    }
    if (halla.size) break
  }
  let lista = [...halla]
  if (lista.some(([, v]) => v.seguro)) lista = lista.filter(([, v]) => v.seguro)
  return lista.sort((a, b) => b[1].n - a[1].n).map(([c]) => c)
}

let zx = null
async function conZxing(img) {
  zx ||= import('@zxing/library')
  const Z = await zx
  const hints = new Map()
  hints.set(Z.DecodeHintType.TRY_HARDER, true)
  // Solo códigos de barras: un QR de la pantalla (la web del producto) taparía el de la etiqueta.
  const reader = new Z.MultiFormatOneDReader(hints)
  for (const lado of [1400, 900, 2200]) {
    for (const giro of [0, 90]) {
      await respiro()
      const [lum, width, height] = grises(lienzo(img, lado, giro))
      try {
        const r = reader.decode(new Z.BinaryBitmap(new Z.HybridBinarizer(new Z.RGBLuminanceSource(lum, width, height))), hints)
        const code = limpiar(r.getText())
        if (valido(code)) return [code]
      } catch { /* aquí no hay código: probar otro giro o tamaño */ }
      reader.reset()
    }
  }
  return []
}

export async function leerCodigos(file) {
  let img
  try { img = await imagen(file) } catch { return [] }
  try {
    const propios = await conCode128(img)
    if (propios.length) return propios
  } catch { /* seguir con los otros */ }
  if ('BarcodeDetector' in window) {
    try {
      const det = new window.BarcodeDetector()
      const hallados = (await det.detect(img)).map((b) => limpiar(b.rawValue)).filter(valido)
      if (hallados.length) return [...new Set(hallados)]
    } catch { /* sin soporte real: zxing */ }
  }
  try { return await conZxing(img) } catch { return [] }
}

/*
 * Reduce una foto antes de subirla: el lado largo a `lado` px y en JPEG. Las del móvil
 * pesan 4-8 MB; así una solicitud con muchas etiquetas sube en segundos. No más pequeña:
 * el código de barras de algunas etiquetas es diminuto y el equipo lo vuelve a leer de
 * esta copia. Si algo falla, se sube la original.
 */
export async function reducirFoto(file, lado = 2200) {
  try {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return file
    const img = await imagen(file)
    if (Math.max(ancho(img), alto(img)) <= lado && file.size < 1.5 * 1024 * 1024) return file
    const c = lienzo(img, lado, 0)
    const blob = await new Promise((ok) => c.toBlob(ok, 'image/jpeg', 0.85))
    return blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file
  } catch { return file }
}
