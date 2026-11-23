<?php
/**
 * Correos transaccionales. Cada envío queda en la bitácora (enviado / no enviado),
 * así el resultado es visible aunque el servidor local no tenga correo configurado.
 *
 * @package InmoChile
 */

defined( 'ABSPATH' ) || exit;

class Inmo_Correo {

	private static function enviar( $para, $asunto, $cuerpo, array $ref ) {
		if ( ! $para ) {
			return false;
		}
		$marca  = Inmo_Ajustes::get( 'marca_nombre' );
		$firma  = "\n\n—\n" . $marca . "\n" . Inmo_Ajustes::get( 'telefono' ) . ' · ' . Inmo_Ajustes::get( 'email' ) . "\n" . home_url( '/' );
		$ok     = wp_mail( $para, '[' . $marca . '] ' . $asunto, $cuerpo . $firma );
		Inmo_Historial::registrar( 'correo', ( $ok ? 'Correo enviado' : 'Correo NO enviado (revisar configuración SMTP)' ) . ': «' . $asunto . '»', $ref );
		return $ok;
	}

	/** Envío genérico con firma de la corredora y registro en la bitácora. */
	public static function enviar_simple( $para, $asunto, $cuerpo, array $ref = array(), $adjuntos = array() ) {
		if ( $adjuntos ) {
			$marca = Inmo_Ajustes::get( 'marca_nombre' );
			$ok    = wp_mail( $para, '[' . $marca . '] ' . $asunto, $cuerpo . "\n\n—\n" . $marca . "\n" . home_url( '/' ), '', $adjuntos );
			Inmo_Historial::registrar( 'correo', ( $ok ? 'Correo enviado' : 'Correo NO enviado (revisar configuración SMTP)' ) . ': «' . $asunto . '»', $ref );
			return $ok;
		}
		return self::enviar( $para, $asunto, $cuerpo, $ref );
	}

	public static function confirmacion_consulta( $consulta_id, array $extra = array() ) {
		$q = Inmo_Consultas::get( $consulta_id );
		$c = $q ? Inmo_Contactos::get( $q->contacto_id ) : null;
		if ( ! $c ) {
			return;
		}
		$plazo = Inmo_Ajustes::get( 'respuesta_plazo', '1 día hábil' );
		$ref   = array( 'propiedad_id' => $q->propiedad_id, 'contacto_id' => $c->id, 'consulta_id' => $q->id );
		switch ( $q->tipo ) {
			case 'visita':
				$asunto = 'Recibimos tu solicitud de visita ' . $q->codigo;
				$cuerpo = "Hola {$c->nombre}:\n\nRecibimos tu solicitud para visitar la propiedad {$q->codigo}. "
					. "La visita AÚN NO está confirmada: un corredor revisará la disponibilidad y te escribirá dentro de {$plazo} para confirmar o proponer otro horario.";
				break;
			case 'captacion':
				$asunto = 'Recibimos tu propiedad ' . $q->codigo;
				$cuerpo = "Hola {$c->nombre}:\n\nGracias por confiarnos tu propiedad. Quedó registrada con el código {$q->codigo} y un corredor la revisará dentro de {$plazo}.";
				if ( ! empty( $extra['invitacion_url'] ) ) {
					$cuerpo .= "\n\nPuedes agregar características y fotos desde este enlace personal (vence el " . Inmo_Util::fecha( $extra['invitacion_vence'] ) . "):\n" . $extra['invitacion_url'] . "\n\nNo compartas este enlace.";
				}
				break;
			case 'valorizacion':
				$asunto = 'Recibimos tu solicitud de valorización';
				$cuerpo = "Hola {$c->nombre}:\n\nRecibimos tu solicitud. Un corredor te contactará dentro de {$plazo} para coordinar la valorización de tu propiedad.";
				break;
			default:
				$asunto = $q->codigo ? 'Recibimos tu consulta por ' . $q->codigo : 'Recibimos tu consulta';
				$cuerpo = "Hola {$c->nombre}:\n\nRecibimos tu consulta" . ( $q->codigo ? " por la propiedad {$q->codigo}" : '' ) . ". Un corredor te responderá dentro de {$plazo}.";
		}
		self::enviar( $c->email, $asunto, $cuerpo, $ref );

		// Aviso interno al corredor responsable.
		$corredor = get_userdata( $q->corredor_id );
		$para     = $corredor ? $corredor->user_email : Inmo_Ajustes::email_avisos();
		$link     = admin_url( 'admin.php?page=inmo-contactos&contacto=' . $c->id );
		self::enviar( $para, 'Nueva ' . strtolower( Inmo_Consultas::TIPOS[ $q->tipo ] ) . ( $q->codigo ? ' · ' . $q->codigo : '' ), "Contacto: {$c->nombre}\nVer en el CRM: {$link}", $ref );
	}

	public static function aviso_visita( $q, $nota = '' ) {
		$c = $q ? Inmo_Contactos::get( $q->contacto_id ) : null;
		if ( ! $c ) {
			return;
		}
		$fecha = Inmo_Util::fecha( $q->visita_fecha, true );
		$map   = array(
			'confirmada'   => array( 'Visita confirmada ' . $q->codigo, "Tu visita a la propiedad {$q->codigo} quedó confirmada para el {$fecha}." ),
			'reprogramada' => array( 'Nueva fecha para tu visita ' . $q->codigo, "Te proponemos visitar la propiedad {$q->codigo} el {$fecha}. Responde este correo si no te acomoda." ),
			'cancelada'    => array( 'Visita cancelada ' . $q->codigo, "La visita a la propiedad {$q->codigo} fue cancelada." ),
		);
		if ( ! isset( $map[ $q->estado ] ) ) {
			return;
		}
		$cuerpo = "Hola {$c->nombre}:\n\n" . $map[ $q->estado ][1] . ( $nota ? "\n\n" . $nota : '' );
		self::enviar( $c->email, $map[ $q->estado ][0], $cuerpo, array( 'propiedad_id' => $q->propiedad_id, 'contacto_id' => $c->id, 'consulta_id' => $q->id ) );
	}

	public static function invitacion( $propiedad_id, $contacto_id, $url, $vence ) {
		$c = Inmo_Contactos::get( $contacto_id );
		if ( ! $c ) {
			return false;
		}
		$codigo = get_post_meta( $propiedad_id, '_inmo_codigo', true );
		return self::enviar(
			$c->email,
			'Completa la ficha de tu propiedad ' . $codigo,
			"Hola {$c->nombre}:\n\nPuedes agregar características y fotos de tu propiedad {$codigo} desde este enlace personal, válido hasta el " . Inmo_Util::fecha( $vence ) . ":\n{$url}\n\nNo compartas este enlace.",
			array( 'propiedad_id' => $propiedad_id, 'contacto_id' => $contacto_id )
		);
	}
}
