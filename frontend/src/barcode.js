/*
 * Leer el código de barras de una foto (la frontal de una etiqueta, la trasera de una
 * antena). Primero el lector del propio navegador (BarcodeDetector: Android, Mac…) y, si
 * no lo hay o no lo encuentra, zxing, que se carga solo cuando hace falta. Prueba la foto
 * tal cual y girada, porque la etiqueta casi nunca sale recta.
 *
 * Devuelve los códigos que parecen de etiqueta (9 letras o números) o de antena (MAC de
 * 12), sin repetir; vacío si no lee ninguno.
 */
const ESL = /^[A-Z0-9]{9}$/
const AP = /^[0-9A-F]{12}$/

const limpiar = (txt) => String(txt || '').toUpperCase().replace(/[\s:\-]/g, '')
const valido = (c) => ESL.test(c) || AP.test(c)

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

let zx = null
async function conZxing(img) {
  zx ||= import('@zxing/library')
  const Z = await zx
  const hints = new Map()
  hints.set(Z.DecodeHintType.TRY_HARDER, true)
  hints.set(Z.DecodeHintType.POSSIBLE_FORMATS, [Z.BarcodeFormat.CODE_128, Z.BarcodeFormat.CODE_39, Z.BarcodeFormat.CODE_93,
    Z.BarcodeFormat.EAN_13, Z.BarcodeFormat.ITF, Z.BarcodeFormat.QR_CODE, Z.BarcodeFormat.DATA_MATRIX])
  const reader = new Z.MultiFormatReader()
  reader.setHints(hints)
  for (const lado of [1400, 900, 2200]) {
    for (const giro of [0, 90, 180, 270]) {
      const c = lienzo(img, lado, giro)
      const { data, width, height } = c.getContext('2d').getImageData(0, 0, c.width, c.height)
      const lum = new Uint8ClampedArray(width * height)
      for (let i = 0, j = 0; i < data.length; i += 4, j++) lum[j] = (data[i] * 299 + data[i + 1] * 587 + data[i + 2] * 114) / 1000
      try {
        const r = reader.decode(new Z.BinaryBitmap(new Z.HybridBinarizer(new Z.RGBLuminanceSource(lum, width, height))))
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
 * pesan 4-8 MB; así una solicitud con muchas etiquetas sube en segundos. Si algo falla,
 * se sube la original.
 */
export async function reducirFoto(file, lado = 1600) {
  try {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return file
    const img = await imagen(file)
    if (Math.max(ancho(img), alto(img)) <= lado && file.size < 1.5 * 1024 * 1024) return file
    const c = lienzo(img, lado, 0)
    const blob = await new Promise((ok) => c.toBlob(ok, 'image/jpeg', 0.85))
    return blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file
  } catch { return file }
}
