import LegalDocument from '../components/legal/LegalDocument'

const TOC = [
  { id: 'naturaleza', label: 'Naturaleza académica' },
  { id: 'cuenta', label: 'Cuenta y registro' },
  { id: 'rifas', label: 'Rifas de demostración' },
  { id: 'pagos', label: 'Pagos de demostración y sandbox' },
  { id: 'resultados', label: 'Resultados y transparencia' },
  { id: 'uso', label: 'Uso aceptable' },
  { id: 'disponibilidad', label: 'Disponibilidad y cambios' },
  { id: 'responsabilidad', label: 'Responsabilidad' },
  { id: 'privacidad', label: 'Privacidad' },
  { id: 'propiedad', label: 'Propiedad intelectual' },
  { id: 'cambios', label: 'Modificaciones' },
  { id: 'ley', label: 'Ley aplicable y contacto' },
]

export default function TermsPage() {
  return (
    <LegalDocument
      eyebrow="Información legal"
      title="Términos y Condiciones del prototipo"
      description="Condiciones de uso del prototipo académico RifaCloud. No son los términos de una plataforma de rifas autorizada."
      toc={TOC}
    >
      <section id="naturaleza">
        <h2>1. Naturaleza académica del servicio</h2>
        <p>
          RifaCloud es un proyecto universitario de computación en la nube. Se
          ofrece como demostración funcional para consultar un catálogo, simular
          reservas y pagos y probar un panel administrativo. No es una empresa
          constituida, no es un operador de juegos de suerte y azar y no cuenta
          con autorización de Coljuegos para operar rifas reales.
        </p>
        <p>
          Las rifas publicadas en el prototipo son de demostración. No hay
          premios reales, no se entregan bienes, no se recauda dinero y no se
          celebran sorteos con efectos jurídicos. Cualquier parecido con una
          operación comercial es únicamente funcional y visual.
        </p>
        <p>
          El proyecto puede ser presentado en un contexto académico controlado.
          No debe usarse para vender boletas, recibir dinero de terceros ni
          promocionar rifas al público.
        </p>
      </section>

      <section id="cuenta">
        <h2>2. Cuenta y registro</h2>
        <ul>
          <li>Para comprar números de demostración debes crear una cuenta y autenticarte.</li>
          <li>Debes proporcionar información veraz y mantenerla actualizada.</li>
          <li>Eres responsable de la confidencialidad de tu contraseña y de la actividad de tu cuenta.</li>
          <li>No debes crear cuentas para suplantar a otra persona ni compartir credenciales.</li>
          <li>El registro exige aceptar la Política de Tratamiento de Datos Personales y estos términos.</li>
          <li>El consentimiento para comunicaciones promocionales es opcional y separado.</li>
        </ul>
        <p>
          El prototipo no implementa todavía verificación de identidad,
          recuperación de contraseña ni eliminación de cuenta desde la interfaz.
          Esas limitaciones se documentan antes de cualquier uso real.
        </p>
      </section>

      <section id="rifas">
        <h2>3. Rifas de demostración</h2>
        <p>
          Las rifas de demostración tienen títulos, precios, rangos de números y
          fechas ficticias o de prueba. La reserva de números durante diez
          minutos y los estados de la orden existen para probar la lógica del
          sistema, no para crear derechos sobre premios.
        </p>
        <p>
          No se garantiza que una rifa de demostración llegue a un sorteo, que se
          publique un resultado ni que exista un premio. La administración puede
          crear, modificar, cerrar o eliminar datos de prueba.
        </p>
        <p>
          La normativa colombiana prohíbe ofrecer juegos de suerte y azar a
          menores de edad. El prototipo no está dirigido a menores y no
          sustituye la verificación de edad que exigiría una operación real.
        </p>
      </section>

      <section id="pagos">
        <h2>4. Pagos de demostración y sandbox</h2>
        <p>
          En el entorno local se usa el gateway simulado interno. En la
          validación de integración se usa Mercado Pago Checkout Pro en modo
          sandbox. Ninguna de esas modalidades cobra dinero real ni transfiere
          fondos a RifaCloud.
        </p>
        <p>
          No debes introducir datos de tarjetas reales ni credenciales
          financieras verdaderas. Si el proveedor sandbox solicita datos de
          prueba, utiliza únicamente los datos de prueba que él mismo indique.
        </p>
        <p>
          La confirmación de un pago de prueba no crea una compra real, no
          transfiere la propiedad de un número y no genera una obligación de
          entrega de premio.
        </p>
      </section>

      <section id="resultados">
        <h2>5. Resultados y transparencia</h2>
        <p>
          En el prototipo, un administrador publica manualmente el número
          ganador y una descripción o enlace de evidencia. No existe un generador
          de números aleatorios certificado, un sorteo público auditado ni una
          integración con una lotería autorizada.
        </p>
        <p>
          Por lo anterior, los resultados solo tienen valor de demostración. No
          deben presentarse como resultados oficiales ni usarse para reclamar un
          premio. Una operación real exigiría reglas predeterminadas, mecanismo
          de sorteo aprobado, evidencia verificable y supervisión de la autoridad
          competente.
        </p>
      </section>

      <section id="uso">
        <h2>6. Uso aceptable</h2>
        <p>No está permitido:</p>
        <ul>
          <li>Usar el prototipo para operar, promocionar o cobrar rifas reales.</li>
          <li>Intentar vulnerar la autenticación, la autorización, la base de datos o los servicios.</li>
          <li>Automatizar ataques, extraer datos de otros usuarios o interferir con la disponibilidad.</li>
          <li>Suplantar a otra persona o falsear la aceptación de los documentos legales.</li>
          <li>Introducir datos personales de terceros sin su autorización.</li>
          <li>Publicar contenido ilícito, engañoso o que afecte derechos de terceros.</li>
        </ul>
        <p>
          El incumplimiento puede llevar a la suspensión de la cuenta de prueba y,
          cuando corresponda, a las acciones académicas o legales aplicables.
        </p>
      </section>

      <section id="disponibilidad">
        <h2>7. Disponibilidad y cambios</h2>
        <p>
          El prototipo se ofrece “tal como está” y “según disponibilidad”. No
          tiene acuerdo de nivel de servicio, soporte 24/7 ni garantía de
          continuidad. Los servicios pueden detenerse para pruebas, actualizaciones
          o corrección de errores.
        </p>
        <p>
          Los datos de demostración pueden reiniciarse, modificarse o eliminarse.
          No uses el prototipo como archivo personal o como comprobante de una
          compra real.
        </p>
      </section>

      <section id="responsabilidad">
        <h2>8. Responsabilidad</h2>
        <p>
          En la medida permitida por la ley, el equipo del proyecto no responde
          por daños derivados del uso del prototipo como si fuera una plataforma
          comercial, por la pérdida de datos de prueba o por la indisponibilidad
          del entorno académico.
        </p>
        <p>
          Nada en estos términos pretende limitar derechos irrenunciables del
          consumidor ni excluir responsabilidades que la ley no permita excluir.
          Como el prototipo no realiza ventas reales, no debe interpretarse como
          una renuncia a derechos que correspondan en una operación futura.
        </p>
      </section>

      <section id="privacidad">
        <h2>9. Privacidad</h2>
        <p>
          El tratamiento de datos personales se rige por la Política de
          Tratamiento de Datos Personales y el aviso de privacidad publicados en
          este sitio. El registro solicita una autorización informada y registra
          su versión, fecha y usuario. El consentimiento promocional es separado.
        </p>
      </section>

      <section id="propiedad">
        <h2>10. Propiedad intelectual</h2>
        <p>
          El código, la interfaz y la documentación del prototipo pertenecen a
          sus autores o a la institución según los acuerdos del proyecto. No se
          concede una licencia para explotar comercialmente el software, las
          marcas o los contenidos de terceros incluidos en la demostración.
        </p>
        <p>
          Los nombres de proveedores y productos se usan solo para identificar
          integraciones técnicas y no implican patrocinio ni autorización.
        </p>
      </section>

      <section id="cambios">
        <h2>11. Modificaciones</h2>
        <p>
          Estos términos pueden cambiar mientras el proyecto evoluciona. La
          versión vigente y su fecha se muestran en esta página. Los cambios
          sustanciales se comunicarán antes de aplicarse y, cuando afecten el
          consentimiento, exigirán una nueva aceptación.
        </p>
      </section>

      <section id="ley">
        <h2>12. Ley aplicable y contacto</h2>
        <p>
          Este documento se interpreta bajo la legislación colombiana, sin que
          ello implique afirmar que el prototipo cumple todos los requisitos de
          una operación comercial. Cualquier controversia real deberá resolverse
          con la asesoría jurídica correspondiente y ante la autoridad competente.
        </p>
        <p>
          Responsable y canal de contacto:
          <span className="legal-placeholder">[RESPONSABLE Y CANAL PENDIENTES]</span>.
        </p>
        <p>
          Para asuntos de protección al consumidor, la autoridad competente es la
          Superintendencia de Industria y Comercio. Para la vigilancia de juegos
          de suerte y azar, la referencia es Coljuegos; este prototipo no afirma
          tener su autorización.
        </p>
      </section>
    </LegalDocument>
  )
}
