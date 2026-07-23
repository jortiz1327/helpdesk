import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { resolve } from 'path'

// El build sale a public/ de Laravel: index.html + assets/ quedan servidos en la
// raíz del dominio, y las llamadas relativas a api/*.php caen en las rutas /api/*.
// emptyOutDir:false para NO borrar index.php ni .htaccess de Laravel al compilar.
export default defineConfig({
  plugins: [react()],
  base: '/',
  // Vite lee el .env de LARAVEL (un nivel arriba), no uno propio en frontend/.
  // Así las VITE_REVERB_* del websocket viven en un único sitio.
  envDir: resolve(__dirname, '..'),
  build: {
    outDir: resolve(__dirname, '../public'),
    emptyOutDir: false,
    assetsDir: 'assets',
  },
  server: {
    port: 5174,
    proxy: {
      // En desarrollo, la API la sirve `php artisan serve` (puerto 8010).
      '/api': 'http://127.0.0.1:8010',
    },
  },
})
