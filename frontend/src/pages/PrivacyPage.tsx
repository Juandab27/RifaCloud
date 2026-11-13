import LegalDocument from '../components/legal/LegalDocument'

const TOC = [
  { id: 'aviso', label: 'Aviso de privacidad resumido' },
  { id: 'responsable', label: 'Responsable y encargados' },
  { id: 'datos', label: 'Datos que tratamos' },
  { id: 'finalidades', label: 'Finalidades' },
  { id: 'autorizacion', label: 'Autorización y consentimiento' },
  { id: 'sensibles', label: 'Datos sensibles y menores de edad' },
  { id: 'derechos', label: 'Derechos del titular' },
  { id: 'procedimiento', label: 'Consultas, correcciones y eliminación' },
  { id: 'conservacion', label: 'Conservación' },
  { id: 'seguridad', label: 'Seguridad' },
  { id: 'terceros', label: 'Terceros y transferencias' },
  { id: 'cookies', label: 'Cookies y sesión' },
  { id: 'vigencia', label: 'Vigencia y cambios' },
  { id: 'contacto', label: 'Contacto' },
]

export default function PrivacyPage() {
  return (
    <LegalDocument
      eyebrow="Información legal"
      title="Política de Tratamiento de Datos Personales"
      description="Borrador de la política aplicable al prototipo académico RifaCloud. No es un documento definitivo ni una política de una empresa real."
      toc={TOC}
    >
      <section id="aviso">
        <h2>Aviso de privacidad resumido</h2>
        <p>
          RifaCloud trata tu nombre, correo electrónico y teléfono para crear y
          administrar tu cuenta, gestionar las compras de demostración y
          enviarte las notificaciones de la orden. También trata datos técnicos
          de sesión y registros necesarios para la seguridad del prototipo.
        </p>
        <p>
          La autorización para el tratamiento es previa, expresa e informada y
          se solicita al registrarte, junto con la aceptación de estos
          documentos. El consentimiento para comunicaciones promocionales es
          <strong> opcional y separado</strong>: no aceptarlo no impide crear la
          cuenta.
        </p>
        <p>
          Puedes conocer, actualizar, rectificar y solicitar la supresión de tus
          datos, así como revocar la autorización, mediante el procedimiento de
          esta política. El canal de atención aún no está habilitado en el
          prototipo: <span className="legal-placeholder">[CANAL DE CONTACTO PENDIENTE]</span>.
        </p>
        <p>
          Esta política completa está disponible en esta misma página. Antes de
          cualquier operación real debe completarse, revisarse jurídicamente y
          publicarse una versión definitiva.
        </p>
      </section>

      <section id="responsable">
        <h2>1. Responsable y encargados del tratamiento</h2>
        <h3>1.1 Responsable</h3>
        <p>
          En una futura operación, el Responsable del Tratamiento será la persona
          natural o jurídica que decida sobre la base de datos y sus finalidades.
          En el estado actual del prototipo esa persona no está definida:
        </p>
        <ul>
          <li>Razón social o nombre: <span className="legal-placeholder">[RESPONSABLE PENDIENTE]</span></li>
          <li>NIT o identificación: <span className="legal-placeholder">[NIT PENDIENTE]</span></li>
          <li>Domicilio y dirección: <span className="legal-placeholder">[DOMICILIO PENDIENTE]</span></li>
          <li>Correo electrónico: <span className="legal-placeholder">[CORREO PENDIENTE]</span></li>
          <li>Teléfono: <span className="legal-placeholder">[TELÉFONO PENDIENTE]</span></li>
        </ul>
        <p>
          No se presenta como responsable a una empresa, universidad o persona
          distinta de la que en el futuro sea designada formalmente. Los datos
          anteriores no pueden inventarse ni tomarse de otra organización.
        </p>

        <h3>1.2 Encargados</h3>
        <p>
          Los proveedores que tratan datos por cuenta del Responsable son
          encargados. En el prototipo se identifican los siguientes, con su
          contrato y rol pendientes de formalización:
        </p>
        <div className="legal-table-wrap">
          <table className="legal-table">
            <thead>
              <tr>
                <th>Proveedor o componente</th>
                <th>Función</th>
                <th>Datos implicados</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Mercado Pago (Checkout Pro, sandbox)</td>
                <td>Procesamiento en el entorno sandbox del proveedor</td>
                <td>Identificadores de pago, estado, monto, URL de checkout</td>
                <td>Integración sandbox; contrato de encargo y condiciones pendientes</td>
              </tr>
              <tr>
                <td>Proveedor de correo SMTP <span className="legal-placeholder">[PENDIENTE]</span></td>
                <td>Entrega de notificaciones de compra e incidencias</td>
                <td>Correo, nombre, contenido de la orden</td>
                <td>En local se usa un mailer de registro; proveedor real pendiente (P2)</td>
              </tr>
              <tr>
                <td>Infraestructura de despliegue <span className="legal-placeholder">[PENDIENTE]</span></td>
                <td>Hosting, base de datos, respaldos y registros</td>
                <td>Todos los datos almacenados</td>
                <td>AWS no ha sido creado; proveedor y región pendientes (P3)</td>
              </tr>
              <tr>
                <td>Equipo de desarrollo y administración</td>
                <td>Soporte, operación y revisión del prototipo</td>
                <td>Acceso administrativo a datos</td>
                <td>Acceso interno; roles y acuerdos de confidencialidad pendientes</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p>
          La universidad, el equipo estudiantil o cualquier futuro operador no
          se presentan aquí como Responsable o Encargado hasta que exista una
          designación y un contrato verificables.
        </p>
      </section>

      <section id="datos">
        <h2>2. Datos personales que tratamos</h2>
        <p>
          El prototipo recopila únicamente los datos necesarios para las
          finalidades descritas. No solicita documento de identidad, datos
          financieros completos ni datos sensibles.
        </p>
        <div className="legal-table-wrap">
          <table className="legal-table">
            <thead>
              <tr>
                <th>Categoría</th>
                <th>Datos</th>
                <th>Dónde se almacenan</th>
                <th>Origen</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Identificación y contacto</td>
                <td>Nombre, correo electrónico, teléfono</td>
                <td>Tabla <code>users</code></td>
                <td>Formulario de registro</td>
              </tr>
              <tr>
                <td>Credenciales</td>
                <td>Contraseña almacenada como hash</td>
                <td>Tabla <code>users</code></td>
                <td>Formulario de registro</td>
              </tr>
              <tr>
                <td>Sesión y seguridad</td>
                <td>Identificador de sesión, usuario asociado, dirección IP, agente de usuario, actividad</td>
                <td>Tabla <code>sessions</code></td>
                <td>Uso del sitio</td>
              </tr>
              <tr>
                <td>Control de abuso</td>
                <td>Claves de límite de intentos y bloqueos de caché</td>
                <td>Tablas <code>cache</code>, <code>cache_locks</code></td>
                <td>Registro, inicio de sesión y coordinación</td>
              </tr>
              <tr>
                <td>Compras de demostración</td>
                <td>Orden, rifa, números, montos, estados y fechas</td>
                <td>Tablas <code>orders</code>, <code>order_items</code>, <code>raffle_numbers</code></td>
                <td>Acciones del usuario autenticado</td>
              </tr>
              <tr>
                <td>Pagos de prueba</td>
                <td>Identificador del proveedor, estado, monto, moneda, URL de checkout, respuesta sanitizada</td>
                <td>Tabla <code>payments</code></td>
                <td>Pasarela de pago en sandbox</td>
              </tr>
              <tr>
                <td>Eventos de webhook</td>
                <td>Identificador y tipo de evento, carga sanitizada, estado de procesamiento</td>
                <td>Tabla <code>payment_webhook_events</code></td>
                <td>Proveedor de pagos</td>
              </tr>
              <tr>
                <td>Notificaciones</td>
                <td>Correo destinatario, asunto y cuerpo con nombre y datos de la orden</td>
                <td>Tabla <code>notifications</code></td>
                <td>Generación automática del sistema</td>
              </tr>
              <tr>
                <td>Evidencia de consentimiento</td>
                <td>Tipo de consentimiento, versión del documento, decisión, fecha y usuario</td>
                <td>Tabla <code>user_consents</code> (S11)</td>
                <td>Registro informado</td>
              </tr>
              <tr>
                <td>Registros técnicos</td>
                <td>Ruta, fecha, IP, agente de usuario, errores de aplicación</td>
                <td>Registros de Nginx, PHP y del planificador</td>
                <td>Operación del sistema</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p>
          La tabla de restablecimiento de contraseña existe en el esquema, pero
          el flujo público de recuperación no está implementado. Las tarjetas y
          datos financieros completos no pasan por RifaCloud: los procesa el
          proveedor de pagos.
        </p>
      </section>

      <section id="finalidades">
        <h2>3. Finalidades del tratamiento</h2>
        <ul>
          <li>Crear la cuenta, autenticar al usuario y mantener la sesión.</li>
          <li>Permitir la consulta del catálogo y la selección de números.</li>
          <li>Crear órdenes de demostración, reservar números y gestionar su vencimiento.</li>
          <li>Iniciar y verificar pagos del gateway simulado o del sandbox del proveedor, sin cobros reales.</li>
          <li>Enviar confirmaciones de compra y avisos de incidencias por correo.</li>
          <li>Prevenir fraude, abuso, accesos no autorizados y doble venta.</li>
          <li>Mantener trazabilidad y evidencia para auditoría, conciliación y atención de reclamaciones.</li>
          <li>Cumplir obligaciones legales que resulten aplicables.</li>
          <li>
            Enviar comunicaciones informativas o promocionales, únicamente si el
            titular otorgó el consentimiento opcional. En el prototipo no se
            envían campañas promocionales.
          </li>
        </ul>
        <p>
          Los datos no se usarán para finalidades incompatibles con las
          anteriores. Cualquier finalidad nueva requerirá información y, cuando
          corresponda, una nueva autorización.
        </p>
      </section>

      <section id="autorizacion">
        <h2>4. Autorización y consentimiento informado</h2>
        <p>
          Al registrarse, la persona debe aceptar de forma separada la presente
          política y los Términos y Condiciones del prototipo. La aceptación se
          registra con la versión exacta de los documentos, la fecha y el usuario.
          Sin esas dos aceptaciones no se crea la cuenta.
        </p>
        <p>
          El consentimiento promocional es una casilla distinta, desmarcada por
          defecto y no obligatoria. La decisión también se registra para poder
          demostrar que no se condicionó el registro a recibir publicidad.
        </p>
        <p>
          La autorización puede revocarse y los datos pueden solicitarse,
          actualizarse, rectificarse o suprimirse mediante el procedimiento de
          esta política. La revocatoria no procede cuando exista un deber legal o
          contractual de conservar la información.
        </p>
      </section>

      <section id="sensibles">
        <h2>5. Datos sensibles y menores de edad</h2>
        <p>
          RifaCloud no solicita datos sensibles (salud, orientación política,
          convicciones religiosas, vida sexual, datos biométricos, etc.). Si en
          el futuro se requirieran, se informaría su carácter facultativo y se
          pediría autorización expresa, conforme a la ley.
        </p>
        <p>
          La normativa de juegos de suerte y azar prohíbe ofrecer o vender estos
          juegos a menores de edad. El prototipo no está dirigido a menores y no
          implementa todavía un mecanismo de verificación de edad o identidad.
          Esa ausencia es una limitación declarada y un requisito pendiente para
          cualquier operación real.
        </p>
      </section>

      <section id="derechos">
        <h2>6. Derechos del titular</h2>
        <p>Como titular de los datos, tienes derecho a:</p>
        <ul>
          <li>Conocer, actualizar y rectificar tus datos personales.</li>
          <li>Solicitar prueba de la autorización otorgada.</li>
          <li>Ser informado sobre el uso que se ha dado a tus datos.</li>
          <li>Presentar quejas ante la Superintendencia de Industria y Comercio.</li>
          <li>Revocar la autorización o solicitar la supresión cuando no exista un deber legal o contractual de conservación.</li>
          <li>Acceder gratuitamente a tus datos personales.</li>
        </ul>
        <p>
          En el prototipo, la consulta de tu perfil y de tus compras ya está
          disponible para el usuario autenticado. La corrección de nombre,
          correo o teléfono, la eliminación de la cuenta y la revocatoria desde
          la interfaz aún no están implementadas. Consulta el documento
          «Derechos del titular y limitaciones» para el detalle y los riesgos.
        </p>
      </section>

      <section id="procedimiento">
        <h2>7. Consultas, correcciones y eliminación</h2>
        <p>
          El canal formal de recepción aún no está definido:
          <span className="legal-placeholder">[CANAL DE ATENCIÓN PENDIENTE]</span>.
          Antes de operar con datos reales debe existir un correo, formulario o
          área responsable, con constancia de recibo y trámite.
        </p>
        <p>
          Una vez habilitado, el procedimiento previsto será:
        </p>
        <ol>
          <li>Recibir la solicitud con identificación suficiente del titular y descripción de lo pedido.</li>
          <li>Registrar la solicitud y, si está incompleta, pedir subsanación en los cinco (5) días siguientes.</li>
          <li>Atender consultas en un máximo de diez (10) días hábiles, prorrogables por cinco (5) días hábiles más.</li>
          <li>Atender reclamos en un máximo de quince (15) días hábiles, prorrogables por ocho (8) días hábiles más.</li>
          <li>Dejar constancia de la decisión y, cuando proceda, actualizar, rectificar o suprimir el dato.</li>
        </ol>
        <p>
          Los términos anteriores son los legales de referencia (Ley 1581 de
          2012, artículos 14 y 15) y deben confirmarse con la revisión jurídica
          antes de publicar el canal definitivo.
        </p>
      </section>

      <section id="conservacion">
        <h2>8. Conservación de los datos</h2>
        <p>
          Los datos se conservarán durante el tiempo razonable y necesario para
          las finalidades autorizadas y para atender obligaciones legales,
          contractuales, contables, fiscales o de defensa ante reclamaciones.
          Las órdenes, pagos y eventos de webhook se conservan para conciliación
          y auditoría; el prototipo no tiene todavía una política de retención
          con plazos aprobados ni un proceso automático de eliminación.
        </p>
        <p>
          Esa ausencia es una limitación conocida. Antes de una operación real se
          debe aprobar una tabla de retención por categoría, definir qué evidencia
          de consentimiento se conserva y documentar las excepciones legales.
        </p>
      </section>

      <section id="seguridad">
        <h2>9. Medidas de seguridad</h2>
        <p>
          El prototipo aplica contraseñas con hash, sesiones autenticadas,
          autorización de administrador en el servidor, separación de dominios
          por servicio, base de datos no expuesta públicamente y secretos fuera
          del código. Los servicios externos se invocan con tiempos de espera y
          reintentos limitados.
        </p>
        <p>
          Todavía no están implementados ni verificados: cifrado en reposo
          definido, gestión formal de llaves, monitoreo centralizado, plan de
          respuesta a incidentes, pruebas de penetración, registro de accesos
          administrativos y contratos de seguridad con encargados. Estos puntos
          son requisitos pendientes, no afirmaciones de cumplimiento.
        </p>
      </section>

      <section id="terceros">
        <h2>10. Terceros, transmisiones y transferencias</h2>
        <p>
          Para operar, el prototipo puede comunicar datos a proveedores de pagos,
          correo o infraestructura, únicamente en la medida necesaria para su
          función. No se venden datos personales ni se comparten con fines
          publicitarios de terceros.
        </p>
        <p>
          No se ha definido si existirán transmisiones o transferencias
          internacionales, ni sus garantías. Antes de contratar un proveedor se
          debe verificar su ubicación, el contrato de transmisión, las cláusulas
          de seguridad y, cuando aplique, la autorización o declaración de
          conformidad correspondiente. Este análisis está pendiente.
        </p>
      </section>

      <section id="cookies">
        <h2>11. Cookies y sesión</h2>
        <p>
          RifaCloud usa cookies técnicas necesarias para la sesión y la
          protección CSRF. No se identificaron herramientas de analítica,
          publicidad o seguimiento de terceros en el código del prototipo. Si se
          agregan, deberán informarse y, cuando corresponda, obtener el
          consentimiento previo.
        </p>
        <p>
          En el entorno local, el correo se escribe en los registros en lugar de
          enviarse. Ese modo no debe usarse con datos de personas reales; los
          registros deben rotarse y restringirse.
        </p>
      </section>

      <section id="vigencia">
        <h2>12. Vigencia, versión y cambios</h2>
        <p>
          Esta es la versión <strong>2026-11-11</strong> del borrador. Se publica
          como versión de trabajo del prototipo, pero no sustituye una política
          definitiva. Los cambios sustanciales se comunicarán antes de
          implementarse y, si afectan la finalidad, exigirán una nueva
          autorización.
        </p>
        <p>
          El Registro Nacional de Bases de Datos (RNBD) se evaluará cuando exista
          un Responsable definido y se determine si se superan los umbrales
          legales. Su inscripción no se afirma como realizada.
        </p>
      </section>

      <section id="contacto">
        <h2>13. Contacto y responsable de protección de datos</h2>
        <p>
          Persona o área responsable de atender consultas y reclamos:
          <span className="legal-placeholder">[RESPONSABLE DE PROTECCIÓN DE DATOS PENDIENTE]</span>.
        </p>
        <p>
          Correo y teléfono de atención:
          <span className="legal-placeholder">[CORREO Y TELÉFONO PENDIENTES]</span>.
        </p>
        <p>
          Mientras estos datos no existan, el prototipo no debe recibir datos
          personales de personas reales fuera del entorno académico controlado.
        </p>
      </section>
    </LegalDocument>
  )
}
