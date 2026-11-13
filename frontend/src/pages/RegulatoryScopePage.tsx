import LegalDocument from '../components/legal/LegalDocument'

const TOC = [
  { id: 'estado', label: 'Estado del prototipo' },
  { id: 'marco', label: 'Marco normativo de referencia' },
  { id: 'definicion', label: 'Qué es una rifa en Colombia' },
  { id: 'roles', label: 'Operador, gestor y plataforma' },
  { id: 'requisitos', label: 'Requisitos para operar' },
  { id: 'prohibiciones', label: 'Prohibiciones y límites' },
  { id: 'transparencia', label: 'Transparencia del sorteo' },
  { id: 'consumidor', label: 'Protección del consumidor' },
  { id: 'brechas', label: 'Brechas del prototipo' },
  { id: 'conclusion', label: 'Conclusión' },
]

export default function RegulatoryScopePage() {
  return (
    <LegalDocument
      eyebrow="Información legal"
      title="Alcance regulatorio de las rifas en Colombia"
      description="Resumen documental del régimen aplicable y de lo que RifaCloud todavía no cumple. No es un concepto jurídico ni una autorización."
      toc={TOC}
    >
      <section id="estado">
        <h2>1. Estado del prototipo</h2>
        <p>
          RifaCloud es una demostración académica. En su estado actual:
        </p>
        <ul>
          <li>No es un operador autorizado de juegos de suerte y azar.</li>
          <li>No cuenta con autorización de Coljuegos ni de otra autoridad competente.</li>
          <li>No vende boletas reales ni recauda dinero real.</li>
          <li>No celebra sorteos con efectos jurídicos ni entrega premios.</li>
          <li>No usa un generador de números aleatorios certificado.</li>
          <li>Publica resultados de demostración de forma manual.</li>
        </ul>
        <p>
          Por lo tanto, ninguna página, rifa o pago del prototipo debe
          interpretarse como una oferta comercial autorizada al público.
        </p>
      </section>

      <section id="marco">
        <h2>2. Marco normativo de referencia</h2>
        <div className="legal-table-wrap">
          <table className="legal-table">
            <thead>
              <tr>
                <th>Norma</th>
                <th>Tema</th>
                <th>Uso en este documento</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Ley 643 de 2001</td>
                <td>Régimen propio del monopolio rentístico de juegos de suerte y azar</td>
                <td>Define la rifa, reserva la explotación a las autoridades competentes y exige operador autorizado.</td>
              </tr>
              <tr>
                <td>Decreto 1486 de 2024</td>
                <td>Reglamento de la modalidad de rifas; modifica la Parte 7 del Libro 2 del Decreto 1068 de 2015</td>
                <td>Define operador, gestor y GNA; fija requisitos, prohibiciones, derechos de explotación y reportes.</td>
              </tr>
              <tr>
                <td>Ley 1581 de 2012</td>
                <td>Protección de datos personales</td>
                <td>Autorización, derechos del titular y deberes de responsables y encargados.</td>
              </tr>
              <tr>
                <td>Decreto 1074 de 2015</td>
                <td>Decreto Único Reglamentario del sector Comercio, Industria y Turismo</td>
                <td>Compila las reglas de autorización, aviso de privacidad, políticas, derechos y Registro Nacional de Bases de Datos.</td>
              </tr>
              <tr>
                <td>Ley 1480 de 2011</td>
                <td>Estatuto del Consumidor</td>
                <td>Información, publicidad, condiciones generales y protección del consumidor.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p>
          Las fuentes oficiales consultadas y sus enlaces se listan en el
          documento «Fuentes oficiales» del repositorio. La vigencia y el alcance
          deben confirmarse con revisión jurídica humana al momento de un
          lanzamiento.
        </p>
      </section>

      <section id="definicion">
        <h2>3. Qué es una rifa en Colombia</h2>
        <p>
          El artículo 27 de la Ley 643 de 2001 define la rifa como una modalidad
          de juego de suerte y azar en la que se sortean, en una fecha
          predeterminada, premios en especie entre quienes adquirieron o poseen
          una o varias boletas, emitidas en serie continua y puestas en venta en
          el mercado a precio fijo por un operador previa y debidamente
          autorizado. La misma norma prohíbe las rifas de carácter permanente.
        </p>
        <p>
          No es una rifa una promoción gratuita sin pago directo, ni un sorteo
          familiar o escolar sin explotación lucrativa, ni las demás actividades
          excluidas por la ley. La calificación concreta de un modelo de negocio
          debe hacerla un abogado con base en los hechos.
        </p>
      </section>

      <section id="roles">
        <h2>4. Operador, gestor y plataforma tecnológica</h2>
        <p>
          El Decreto 1486 de 2024 distingue dos figuras principales:
        </p>
        <ul>
          <li>
            <strong>Operador:</strong> persona jurídica autorizada en Colombia
            para operar la modalidad de rifas. Coordina a los gestores, aporta la
            infraestructura operativa y tecnológica, liquida y garantiza el
            recaudo de derechos de explotación y responde por las obligaciones
            del juego.
          </li>
          <li>
            <strong>Gestor:</strong> persona natural o jurídica interesada en la
            realización, organización, comercialización y promoción de la rifa,
            que actúa a través de un operador autorizado y puede ofrecerla de
            forma física o electrónica mediante plataforma web o redes sociales.
          </li>
        </ul>
        <p>
          RifaCloud no está definido como operador ni como gestor. Una plataforma
          tecnológica puede ser un medio o proveedor de infraestructura para un
          operador o gestor, pero esa relación requiere contrato, autorización y
          análisis jurídico. El prototipo no puede autoproclamarse en ninguno de
          esos roles.
        </p>
      </section>

      <section id="requisitos">
        <h2>5. Requisitos que debería cumplir una operación real</h2>
        <p>
          A partir de la Ley 643 de 2001 y el Decreto 1486 de 2024, una operación
          comercial de rifas exige, como mínimo y sujeto a la reglamentación
          vigente:
        </p>
        <div className="legal-table-wrap">
          <table className="legal-table">
            <thead>
              <tr>
                <th>Requisito</th>
                <th>Contenido de referencia</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Operador autorizado</td>
                <td>Persona jurídica previamente autorizada por la autoridad competente, con la infraestructura y las garantías exigidas.</td>
              </tr>
              <tr>
                <td>Registro de la rifa</td>
                <td>Solicitud ante un operador autorizado, con identificación del responsable, nombre de la rifa, valor de la boleta, número de boletas, valor de la emisión y plan de premios.</td>
              </tr>
              <tr>
                <td>Antelación</td>
                <td>Presentación con al menos treinta (30) días calendario de anterioridad a la fecha del sorteo.</td>
              </tr>
              <tr>
                <td>Soporte de premios</td>
                <td>Propiedad de los bienes, avalúo o facturas y garantía de cumplimiento por el valor del plan de premios.</td>
              </tr>
              <tr>
                <td>Boleta o tiquete</td>
                <td>Contenido mínimo: número, valor, lugar, fecha y hora del sorteo, caducidad, descripción de premios, responsable y autorización.</td>
              </tr>
              <tr>
                <td>Mecanismo del sorteo</td>
                <td>Resultados de una lotería tradicional autorizada o un Generador de Números Aleatorios (GNA) validado; el GNA se limita a seis dígitos y a emisiones de hasta un millón de números.</td>
              </tr>
              <tr>
                <td>Derechos de explotación</td>
                <td>14 % de los ingresos brutos, equivalentes al 100 % del valor de las boletas emitidas, con ajuste al valor vendido.</td>
              </tr>
              <tr>
                <td>Plan de premios</td>
                <td>Como mínimo el 40 % del valor de la emisión.</td>
              </tr>
              <tr>
                <td>Reportes previos</td>
                <td>Reporte de boletas emitidas, vendidas, no vendidas e invalidadas a más tardar el día hábil anterior al sorteo, según el caso.</td>
              </tr>
              <tr>
                <td>Entrega y verificación</td>
                <td>Entrega del premio y declaración jurada ante notario dentro de los cinco (5) días hábiles siguientes, o el mecanismo equivalente previsto para ciertos operadores.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p>
          Esta tabla es un resumen documental, no una lista exhaustiva ni una
          interpretación jurídica definitiva. Los requerimientos técnicos que
          expidan Coljuegos y el Consejo Nacional de Juegos de Suerte y Azar
          también son aplicables.
        </p>
      </section>

      <section id="prohibiciones">
        <h2>6. Prohibiciones y límites</h2>
        <ul>
          <li>Rifas permanentes: el gestor no puede realizarlas en más de dos fechas al mes.</li>
          <li>Boletas con series o fraccionadas.</li>
          <li>Rifas de bienes usados.</li>
          <li>Rifas con premios en dinero.</li>
          <li>Operación o venta manual, salvo las excepciones y el periodo de transición previstos.</li>
          <li>Comercializar rifas no validadas o no registradas ante un operador autorizado.</li>
          <li>Ofrecer o vender juegos de suerte y azar a menores de edad (Ley 643, artículo 4).</li>
          <li>Operar sin autorización o por fuera de las reglas y límites autorizados (Ley 643, artículo 4).</li>
        </ul>
      </section>

      <section id="transparencia">
        <h2>7. Transparencia del sorteo</h2>
        <p>
          Una operación real debe informar con claridad las reglas, la fecha y
          hora del sorteo, el mecanismo utilizado, el plan de premios, el valor
          de la boleta, el número de boletas, la caducidad del premio y la
          autorización correspondiente. También debe poder demostrar que el
          resultado no fue alterado y que el premio se entregó.
        </p>
        <p>
          El prototipo solo permite que un administrador publique manualmente un
          número ganador y una evidencia descriptiva. No hay GNA certificado, no
          hay auditoría independiente, no hay acta de sorteo y no hay integración
          con una lotería. Por eso el resultado es únicamente demostrativo.
        </p>
      </section>

      <section id="consumidor">
        <h2>8. Protección del consumidor</h2>
        <p>
          La Ley 1480 de 2011 exige información completa, veraz, transparente,
          oportuna, verificable, comprensible y precisa; prohíbe la publicidad
          engañosa y las cláusulas abusivas, y reconoce derechos de reclamación y
          acceso a la autoridad de protección al consumidor. Una plataforma real
          debe informar la identidad del proveedor, las condiciones, el precio,
          las restricciones y el procedimiento de reclamación.
        </p>
        <p>
          El prototipo debe evitar que su presentación se confunda con una venta
          real. Por eso mantiene los documentos legales en el pie de página y
          muestra una aclaración contextual al iniciar el checkout de
          demostración.
        </p>
      </section>

      <section id="brechas">
        <h2>9. Brechas del prototipo frente a una operación real</h2>
        <div className="legal-table-wrap">
          <table className="legal-table">
            <thead>
              <tr>
                <th>Área</th>
                <th>Estado actual</th>
                <th>Requisito pendiente</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Autorización</td>
                <td>No existe</td>
                <td>Contrato con operador autorizado y autorización de la rifa.</td>
              </tr>
              <tr>
                <td>Sorteo</td>
                <td>Resultado manual</td>
                <td>GNA certificado o lotería autorizada, acta y evidencia verificable.</td>
              </tr>
              <tr>
                <td>Boletas</td>
                <td>Números internos</td>
                <td>Emisión, boleta electrónica y contenido mínimo legal.</td>
              </tr>
              <tr>
                <td>Derechos de explotación</td>
                <td>No aplica</td>
                <td>Liquidación, pago y reportes al operador y a la autoridad.</td>
              </tr>
              <tr>
                <td>Premios y garantías</td>
                <td>No existen</td>
                <td>Plan de premios, propiedad, avalúo y garantía de cumplimiento.</td>
              </tr>
              <tr>
                <td>Edad e identidad</td>
                <td>Sin verificación</td>
                <td>Restricción de menores y validación de identidad del ganador.</td>
              </tr>
              <tr>
                <td>Pagos</td>
                <td>Gateway simulado / sandbox</td>
                <td>Producción, conciliación, reembolsos, contracargos y contabilidad.</td>
              </tr>
              <tr>
                <td>Consumidor</td>
                <td>Aviso de prototipo</td>
                <td>Información completa, publicidad aprobada y canal de reclamación.</td>
              </tr>
              <tr>
                <td>Datos personales</td>
                <td>Borrador y consentimiento</td>
                <td>Responsable, contratos, RNBD si aplica, retención y seguridad verificada.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section id="conclusion">
        <h2>10. Conclusión</h2>
        <p>
          RifaCloud puede demostrar arquitectura, catálogo, reservas, pagos de
          demostración y administración, pero no puede operar rifas comerciales por
          sí mismo. Para hacerlo necesitaría integrarse con un operador autorizado,
          cumplir los requisitos de la modalidad, obtener las autorizaciones
          aplicables y someterse a revisión jurídica, contable y de protección al
          consumidor.
        </p>
        <p>
          Este documento no afirma que RifaCloud esté autorizado por Coljuegos ni
          que cumpla el régimen de rifas. Su finalidad es dejar explícito el
          alcance y evitar que la demostración se presente como una operación
          legalmente habilitada.
        </p>
      </section>
    </LegalDocument>
  )
}
