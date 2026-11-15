import ContactEmail from '../components/ContactEmail'
import LegalDocument from '../components/legal/LegalDocument'

const TOC = [
  { id: 'naturaleza', label: 'Naturaleza del proyecto' },
  { id: 'cuenta', label: 'Cuenta y registro' },
  { id: 'uso', label: 'Uso permitido' },
  { id: 'rifas', label: 'Rifas de demostración' },
  { id: 'pagos', label: 'Pagos de prueba' },
  { id: 'resultados', label: 'Resultados' },
  { id: 'privacidad', label: 'Privacidad' },
  { id: 'disponibilidad', label: 'Disponibilidad, cambios y contacto' },
]

export default function TermsPage() {
  return (
    <LegalDocument
      eyebrow="Información legal"
      title="Términos y Condiciones"
      description="Condiciones de uso del prototipo académico RifaCloud."
      toc={TOC}
    >
      <section id="naturaleza">
        <h2>1. Naturaleza del proyecto</h2>
        <p>
          RifaCloud es un proyecto académico universitario de computación en la
          nube. Lo presenta el <strong>Equipo de RifaCloud</strong>, que no es una
          empresa ni una persona jurídica constituida.
        </p>
        <p>
          El proyecto no es un operador de juegos de suerte y azar, no cuenta con
          autorización de Coljuegos y no opera rifas comerciales. Las rifas,
          reservas, pagos y resultados que se muestran son demostrativos.
        </p>
      </section>

      <section id="cuenta">
        <h2>2. Cuenta y registro</h2>
        <p>
          Para usar las funciones de compra de demostración debes crear una cuenta
          y autenticarte. Debes proporcionar información veraz y mantener la
          confidencialidad de tu contraseña.
        </p>
        <p>
          No está permitido suplantar a otra persona, compartir credenciales ni
          crear cuentas con datos falsos. El registro exige aceptar la Política de
          Tratamiento de Datos Personales y estos Términos.
        </p>
      </section>

      <section id="uso">
        <h2>3. Uso permitido</h2>
        <p>El prototipo debe usarse únicamente con fines académicos y de prueba.</p>
        <ul>
          <li>No lo uses para vender boletas, recibir dinero de terceros o promocionar rifas reales.</li>
          <li>No intentes vulnerar la autenticación, la autorización, la base de datos o los servicios.</li>
          <li>No extraigas datos de otros usuarios ni interfieras con la disponibilidad del sitio.</li>
          <li>No introduzcas datos personales de terceros sin su autorización.</li>
          <li>No publiques contenido ilícito, engañoso o que afecte derechos de terceros.</li>
        </ul>
        <p>
          El incumplimiento puede llevar a la suspensión de la cuenta de prueba y,
          cuando corresponda, a las acciones académicas o legales aplicables.
        </p>
      </section>

      <section id="rifas">
        <h2>4. Rifas de demostración</h2>
        <p>
          Las rifas tienen títulos, precios, rangos de números y fechas de prueba.
          La reserva de números y los estados de la orden permiten demostrar la
          lógica del sistema; no crean derechos sobre premios ni obligaciones de
          entrega.
        </p>
        <p>
          No se garantiza que una rifa llegue a un sorteo, que se publique un
          resultado o que exista un premio. La administración puede crear,
          modificar, cerrar o eliminar datos de prueba.
        </p>
        <p>
          El prototipo no está dirigido a menores de edad y no sustituye la
          verificación de edad o identidad que exigiría una operación real.
        </p>
      </section>

      <section id="pagos">
        <h2>5. Pagos de prueba</h2>
        <p>
          Los pagos se procesan con el gateway simulado interno o con Mercado Pago
          Checkout Pro en modo sandbox. Ninguna de esas modalidades cobra dinero
          real ni transfiere fondos al equipo del proyecto.
        </p>
        <p>
          No introduzcas datos de tarjetas reales ni credenciales financieras
          verdaderas. Un pago aprobado en el entorno de prueba no constituye una
          compra real ni genera una obligación de entrega de premio.
        </p>
      </section>

      <section id="resultados">
        <h2>6. Resultados</h2>
        <p>
          El administrador puede publicar un resultado manual o configurar un
          sorteo automático entre números vendidos con orden pagada y pago
          aprobado. El sistema registra evidencia y publica el número ganador con
          un nombre enmascarado del comprador.
        </p>
        <p>
          El resultado es únicamente demostrativo. No es un sorteo certificado ni
          tiene efectos jurídicos, y no debe presentarse como resultado oficial ni
          usarse para reclamar un premio.
        </p>
      </section>

      <section id="privacidad">
        <h2>7. Privacidad</h2>
        <p>
          El tratamiento de datos personales se rige por la Política de
          Tratamiento de Datos Personales publicada en este sitio. El registro
          solicita una autorización informada y registra la versión del documento,
          la fecha y el usuario.
        </p>
        <p>
          Para consultas sobre datos personales puedes escribir a{' '}
          <ContactEmail />.
        </p>
      </section>

      <section id="disponibilidad">
        <h2>8. Disponibilidad, cambios y contacto</h2>
        <p>
          El prototipo se ofrece “tal como está” y según disponibilidad. No tiene
          acuerdo de nivel de servicio ni garantía de continuidad. Los datos de
          demostración pueden modificarse o eliminarse durante las pruebas.
        </p>
        <p>
          Estos términos pueden actualizarse mientras el proyecto evoluciona. La
          versión vigente y su fecha se muestran al inicio del documento. Los
          cambios sustanciales que afecten el consentimiento requerirán una nueva
          aceptación.
        </p>
        <p>
          Contacto del proyecto: <strong>Equipo de RifaCloud</strong>,{' '}
          <ContactEmail />. Este documento se
          interpreta bajo la legislación colombiana, sin afirmar que el prototipo
          cumpla todos los requisitos de una operación comercial.
        </p>
      </section>
    </LegalDocument>
  )
}
