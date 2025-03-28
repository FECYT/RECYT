# Plugin de Calidad FECYT

Módulo para OJS que automatiza la descarga de parte de la documentación requerida por la Fundación Española para la Ciencia y Tecnología en los procesos de acreditación para la obtención y/o renovación del sello de calidad FECYT.

El plugin ha sido desarrollado para OJS 3.4, siendo esta última la versión recomendada.
Las distitas versiones del plugin se publican aquí: [https://github.com/FECYT/RECYT/releases](https://github.com/FECYT/RECYT/releases)

Más información en [https://calidadrevistas.fecyt.es](https://calidadrevistas.fecyt.es)

### Instalación

##### - Instalación web

- Descargar la [última versión del plugin](https://github.com/FECYT/RECYT/releases) en formato '.tar.gz' para la versión de OJS que corresponda.
- Desde el panel de administración general o de una revista
- Acceder a la página de 'Módulos'
- Hacer click en 'Cargar un nuevo módulo' y subir el fichero .tar.gz proporcionado

##### - Instalación manual

- Descargar la [última versión del plugin](https://github.com/FECYT/RECYT/releases) en formato '.tar.gz' para la versión de OJS que corresponda.
- Descomprimir y copiar los contenidos de este repositorio en `[OJS]/plugins/generic/calidadfecyt/`
- Confirmar que se ha descomprimido en el directorio indicado y con los permisos correctos.

### Activación del plugin

Activar el plugin desde el OJS:

- Desde el panel de administración de una revista
- Ir a: 'Ajustes' > 'Sitio web' > 'Módulos' > 'Módulos instalados'
- En la sección de 'Módulos Genéricos', buscar el plugin 'Calidad FECYT'
- Activar el plugin haciendo click en el checkbox, a la derecha de la descripción del plugin

### Descarga de documentación

Una vez que el plugin está activado, se mostrará, en las acciones del plugin, un enlace 'Ajustes' que abrirá una nueva ventana.  
Desde esta ventana se puede descargar la documentación de la revista, haciendo click en el botón 'Exportar' de cada sección.
