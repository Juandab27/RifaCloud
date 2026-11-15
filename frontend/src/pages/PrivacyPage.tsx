import ContactEmail from '../components/ContactEmail'
import LegalDocument from '../components/legal/LegalDocument'

const TOC = [
  { id: 'quienes', label: 'Quiénes somos y contacto' },
  { id: 'datos', label: 'Datos que tratamos' },
  { id: 'finalidades', label: 'Para qué usamos los datos' },
  { id: 'autorizacion', label: 'Autorización y consentimiento' },
  { id: 'derechos', label: 'Tus derechos' },
  { id: 'solicitudes', label: 'Consultas, correcciones y supresión' },
  { id: 'conservacion', label: 'Conservación y seguridad' },
  { id: 'proveedores', label: 'Proveedores' },
  { id: 'cambios', label: 'Cambios y vigencia' },
]

export default function PrivacyPage() {
  return (
    <LegalDocument
      eyebrow="Información legal"
      title="Política de Tratamiento de Datos Personales"
      description="Cómo tratamos los datos personales en el prototipo académico RifaCloud."
      toc={TOC}
    >
      <section id="quienes">
        <h2>1. Quiénes somos y contacto</h2>
        <p>
          RifaCloud es un proyecto académico universitario. El responsable del
          tratamiento es el equipo del proyecto, identificado públicamente como
          <strong> Equipo de RifaCloud</strong>. Esa denominación corresponde al
          equipo académico y no implica que exista una empresa o persona jurídica
          constituida.
        </p>
        <p>
          Para asuntos de privacidad puedes escribir a{' '}
          <ContactEmail />.
        </p>
      </section>

      <section id="datos">
        <h2>2. Datos que tratamos</h2>
        <p>Tratamos únicamente los datos necesarios para operar el prototipo:</p>
        <ul>
          <li><strong>Cuenta:</strong> nombre, correo electrónico y teléfono.</li>
          <li><strong>Credenciales:</strong> contraseña almacenada mediante hash, nunca en texto plano.</li>
          <li><strong>Actividad:</strong> reservas, órdenes, números, montos en pesos colombianos, estados y fechas.</li>
          <li><strong>Propuestas:</strong> título, descripción y datos de contacto tomados de la cuenta.</li>
          <li><strong>Pagos de prueba:</strong> identificadores del proveedor, estado, monto y moneda. No almacenamos datos completos de tarjetas.</li>
          <li><strong>Resultados:</strong> número ganador y nombre enmascarado del comprador.</li>
          <li><strong>Consentimiento:</strong> tipo de documento, versión, decisión, fecha y usuario.</li>
          <li><strong>Seguridad:</strong> dirección IP, agente de usuario, sesión y registros técnicos necesarios para prevenir abuso y diagnosticar fallos.</li>
        </ul>
        <p>
          No solicitamos documento de identidad, datos sensibles ni información
          financiera completa. El prototipo no está dirigido a menores de edad.
        </p>
      </section>

      <section id="finalidades">
        <h2>3. Para qué usamos los datos</h2>
        <ul>
          <li>Crear y administrar tu cuenta, autenticarte y mantener tu sesión.</li>
          <li>Permitir la consulta del catálogo, la reserva de números y la gestión de órdenes de demostración.</li>
          <li>Iniciar y verificar pagos de prueba con el gateway simulado o Mercado Pago sandbox.</li>
          <li>Recibir y revisar las propuestas de rifas, y contactar al proponente si es necesario.</li>
          <li>Publicar resultados demostrativos con el número ganador y un nombre enmascarado.</li>
          <li>Prevenir fraude, abuso, accesos no autorizados y doble venta.</li>
          <li>Mantener trazabilidad, evidencia de consentimiento y atención de reclamaciones.</li>
          <li>Cumplir obligaciones legales que resulten aplicables.</li>
        </ul>
        <p>
          No usamos tus datos para publicidad ni vendemos datos personales.
        </p>
      </section>

      <section id="autorizacion">
        <h2>4. Autorización y consentimiento</h2>
        <p>
          Al registrarte debes aceptar esta política y los Términos y Condiciones.
          La aceptación se registra con la versión del documento, la fecha y el
          usuario. Sin esas dos aceptaciones no se crea la cuenta.
        </p>
        <p>
          La autorización es previa, expresa e informada. Puedes revocarla o
          solicitar la supresión de tus datos cuando no exista un deber legal o
          contractual de conservación, mediante el procedimiento descrito abajo.
        </p>
      </section>

      <section id="derechos">
        <h2>5. Tus derechos</h2>
        <p>Como titular de los datos tienes derecho a:</p>
        <ul>
          <li>Conocer, actualizar y rectificar tus datos personales.</li>
          <li>Solicitar prueba de la autorización otorgada.</li>
          <li>Ser informado sobre el uso que se ha dado a tus datos.</li>
          <li>Presentar quejas ante la Superintendencia de Industria y Comercio.</li>
          <li>Revocar la autorización o solicitar la supresión cuando sea procedente.</li>
          <li>Acceder gratuitamente a tus datos personales.</li>
        </ul>
      </section>

      <section id="solicitudes">
        <h2>6. Consultas, correcciones y supresión</h2>
        <p>
          Envía tu solicitud a{' '}
          <ContactEmail /> con tu nombre,
          correo de la cuenta y una descripción clara de lo que necesitas. Podemos
          pedir información adicional para verificar tu identidad y evitar
          modificaciones no autorizadas.
        </p>
        <p>
          Atenderemos consultas en un máximo de diez (10) días hábiles y reclamos
          en un máximo de quince (15) días hábiles, plazos que pueden prorrogarse
          conforme a la Ley 1581 de 2012. Si la solicitud está incompleta, te
          pediremos subsanarla.
        </p>
      </section>

      <section id="conservacion">
        <h2>7. Conservación y seguridad</h2>
        <p>
          Conservamos los datos mientras sean necesarios para las finalidades
          autorizadas, la operación del prototipo y el cumplimiento de obligaciones
          legales, contables o de auditoría. Los respaldos que se generen se
          conservan por tiempo limitado según su ciclo de retención.
        </p>
        <p>
          Aplicamos contraseñas con hash, sesiones autenticadas, autorización en el
          servidor, HTTPS, base de datos no expuesta públicamente y secretos fuera
          del código. Ningún sistema es completamente infalible; si detectamos un
          incidente relevante, lo gestionaremos y comunicaremos según corresponda.
        </p>
      </section>

      <section id="proveedores">
        <h2>8. Proveedores</h2>
        <p>
          Para operar el prototipo usamos proveedores externos que reciben solo la
          información necesaria para su función:
        </p>
        <ul>
          <li><strong>Amazon Web Services (AWS):</strong> alojamiento de la aplicación y la base de datos.</li>
          <li><strong>Mercado Pago Checkout Pro en modo sandbox:</strong> procesamiento de pagos de demostración. No almacenamos datos completos de tarjetas.</li>
        </ul>
        <p>
          No compartimos datos con anunciantes ni los vendemos. Cualquier proveedor
          adicional se incorporará a esta política antes de tratar datos personales
          para una nueva finalidad.
        </p>
      </section>

      <section id="cambios">
        <h2>9. Cambios y vigencia</h2>
        <p>
          Esta política puede actualizarse mientras el proyecto evoluciona. La
          versión y la fecha vigentes se muestran al inicio del documento. Los
          cambios sustanciales que afecten la finalidad o el consentimiento
          requerirán una nueva aceptación.
        </p>
        <p>
          Contacto: <strong>Equipo de RifaCloud</strong>,{' '}
          <ContactEmail />.
        </p>
      </section>
    </LegalDocument>
  )
}
