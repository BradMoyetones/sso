# Criptografía y Almacén de Caché

La seguridad del SDK descansa sobre dos pilares: cifrado autenticado en reposo para las cookies y un almacén de sincronización concurrente libre de condiciones de carrera.

---

## 🔐 Cifrado Autenticado (`TokenCipher`)

`Fpjd\Sso\Security\TokenCipher` utiliza el algoritmo simétrico **AES-256-GCM** (Galois/Counter Mode).

### ¿Por qué AES-256-GCM?
A diferencia de CBC o ECB, GCM provee **Confidencialidad + Integridad Autenticada**:
- Genera un vector de inicialización (**IV**) aleatorio de 12 bytes por cada operación.
- Genera un Authentication Tag (**MAC**) de 16 bytes que previene cualquier intento de manipulación o alteración de bits en tránsito.

### Datos Autenticados Adicionales (AAD) con `$purpose`
Al cifrar, se incluye un parámetro `$purpose` que queda criptográficamente atado al texto cifrado:
```php
$encrypted = $cipher->encrypt($jsonString, purpose: 'account');
$decrypted = $cipher->decrypt($encrypted, purpose: 'account'); // Correcto

// Si un atacante copia una cookie de flujo temporal para usarla como cookie de sesión:
$cipher->decrypt($encrypted, purpose: 'flow'); // Retorna NULL inmediatamente
```

### Configuración de Clave (`APP_KEY`)
La clave debe ser de exactamente **64 caracteres hexadecimales** (equivalente a 32 bytes en binario / 256 bits).
Se puede generar con:
```bash
openssl rand -hex 32
```

---

## 🗄️ Caché y Mutex Locks (`Store` y `FileStore`)

### `Store`
Define el contrato para cualquier almacén de datos temporal:
```php
interface Store
{
    public function get(string $key): ?string;
    public function put(string $key, string $value, int $ttl): void;
    public function lock(string $name, float $waitSeconds = 5.0);
    public function unlock($handle): void;
}
```

### `FileStore`
Es la implementación nativa basada en sistema de archivos:
- Guarda entradas en archivos `.cache` con encabezado de expiración en la primera línea.
- Implementa bloqueos exclusivos mediante la llamada a sistema `flock($handle, LOCK_EX | LOCK_NB)`.
- Si se adquiere un lock sobre un recurso (ejemplo: `refresh:<uuid>`), cualquier otro hilo esperará hasta `$waitSeconds` (5 segundos por defecto) antes de fallar.
- Realiza recolección oportunista de basura (*Garbage Collection*) para eliminar archivos caducados sin necesidad de crons externos.
- Directorio de almacenamiento configurable mediante constructor o variable `SSO_CACHE_DIR`. Por defecto utiliza `./cache/sso` o el directorio temporal del sistema (`sys_get_temp_dir()`).
