"""
Prueba de punta a punta del Sistema de Subdirección.
Uso:  python3 tests/e2e.py http://127.0.0.1:8080 <SETUP_TOKEN>
Requiere una base recién migrada (database/001 y 002).
"""
import re, sys, hashlib, requests

BASE = sys.argv[1].rstrip('/')
TOKEN = sys.argv[2]
ok_count = 0

def check(cond, msg):
    global ok_count
    if not cond:
        print('FALLA:', msg); sys.exit(1)
    ok_count += 1
    print('  ✓', msg)

def csrf(html):
    m = re.search(r'name="csrf_token" value="([a-f0-9]{64})"', html)
    assert m, 'sin csrf'
    return m.group(1)

def session():
    s = requests.Session()
    return s

def login(s, user, pw, expect_ok=True):
    r = s.get(BASE + '/login.php')
    r = s.post(BASE + '/login.php', data={'csrf_token': csrf(r.text), 'identificador_login': user, 'password': pw, 'next': 'index.php'})
    return r

def make_pdf(text):
    body = f"%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n4 0 obj<</Length {len(text)+30}>>stream\nBT /F1 18 Tf 72 700 Td ({text}) Tj ET\nendstream endobj\n5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"
    return body.encode('latin-1')

print('1. Acceso sin sesión')
s0 = session()
r = s0.get(BASE + '/index.php', allow_redirects=False)
check(r.status_code == 302 and 'login.php' in r.headers['Location'], 'index redirige a login')
check(s0.get(BASE + '/health.php').text == 'ok', 'health.php responde ok')
r = s0.get(BASE + '/planeaciones.php?grupo=1', allow_redirects=False)
check('next=planeaciones.php%3Fgrupo%3D1' in r.headers['Location'], 'conserva la página de retorno')

print('2. Configuración inicial con SETUP_TOKEN')
r = s0.get(BASE + '/configuracion_inicial.php')
check('GEORGINA' in r.text and 'acceso033' in r.text, 'muestra la cuenta de la Subdirectora')
uid = re.search(r'<option value="(\d+)">GEORGINA', r.text).group(1)
r2 = s0.post(BASE + '/configuracion_inicial.php', data={'csrf_token': csrf(r.text), 'setup_token': 'malo', 'usuario_id': uid, 'password': 'x' * 12, 'password_confirm': 'x' * 12})
check('no es correcto' in r2.text, 'rechaza token incorrecto')
r2 = s0.post(BASE + '/configuracion_inicial.php', data={'csrf_token': csrf(r.text), 'setup_token': TOKEN, 'usuario_id': uid, 'password': 'Subdireccion-2026!', 'password_confirm': 'Subdireccion-2026!'})
check('Listo' in r2.text, 'define la contraseña de la Subdirectora')

print('3. Inicio de sesión de la Subdirectora')
sub = session()
r = login(sub, 'acceso033', 'contraseña-incorrecta')
check('No fue posible iniciar' in r.text, 'contraseña incorrecta rechazada')
r = login(sub, 'ACCESO033', 'Subdireccion-2026!')
check('Sesión iniciada' in r.text and 'GEORGINA' in r.text, 'entra (usuario sin distinguir mayúsculas)')
check('Administración de cuentas' in r.text, 've el módulo de administración')

print('4. Administración de cuentas')
r = sub.get(BASE + '/activar_cuenta.php')
check('MIRAFLORES' in r.text, 'lista cuentas pendientes')
def uid_of(html, login_id):
    return re.search(r'<option value="(\d+)">[^<]*— ' + login_id, html).group(1)
r2 = sub.post(BASE + '/activar_cuenta.php', data={'csrf_token': csrf(r.text), 'usuario_id': uid_of(r.text, 'acceso017'), 'accion': 'activar', 'modo': 'generar'})
m = re.search(r'<code>([A-Za-z0-9-]{19})</code>', r2.text)
check(m is not None, 'genera contraseña temporal para apoyo (acceso017)')
pw_apoyo = m.group(1)
r = sub.get(BASE + '/activar_cuenta.php')
r2 = sub.post(BASE + '/activar_cuenta.php', data={'csrf_token': csrf(r.text), 'usuario_id': uid_of(r.text, 'acceso002'), 'accion': 'activar', 'modo': 'escribir', 'password': 'corta', 'password_confirm': 'corta'})
check('al menos 12' in r2.text, 'valida longitud mínima')
r2 = sub.post(BASE + '/activar_cuenta.php', data={'csrf_token': csrf(r.text), 'usuario_id': uid_of(r.text, 'acceso002'), 'accion': 'activar', 'modo': 'escribir', 'password': 'Temporal-Docente1', 'password_confirm': 'Temporal-Docente1'})
check('Cuenta activada' in r2.text, 'activa docente (acceso002) con contraseña escrita')

print('5. Cambio de contraseña obligatorio')
doc = session()
r = login(doc, 'acceso002', 'Temporal-Docente1')
check('Cambiar contraseña' in r.text and 'define una contraseña personal' in r.text, 'docente es enviado a cambiar contraseña')
r = doc.get(BASE + '/planeaciones.php')
check('define una contraseña personal' in r.text, 'no puede navegar sin cambiarla')
r2 = doc.post(BASE + '/cambiar_contrasena.php', data={'csrf_token': csrf(r.text), 'actual': 'Temporal-Docente1', 'nueva': 'MiClaveDocente-2026', 'confirmar': 'MiClaveDocente-2026'})
check('se actualizó' in r2.text, 'cambia su contraseña')
check('Mis planeaciones' in doc.get(BASE + '/index.php').text, 'docente ve "Mis planeaciones"')

print('6. Permisos por rol')
r = doc.get(BASE + '/carga_masiva_planeaciones.php')
check(r.status_code == 403, 'docente no puede cargar lotes')
r = doc.get(BASE + '/activar_cuenta.php')
check(r.status_code == 403, 'docente no administra cuentas')
apoyo = session()
login(apoyo, 'acceso017', pw_apoyo)
r = apoyo.get(BASE + '/cambiar_contrasena.php')
apoyo.post(BASE + '/cambiar_contrasena.php', data={'csrf_token': csrf(r.text), 'actual': pw_apoyo, 'nueva': 'ApoyoSubdireccion-26', 'confirmar': 'ApoyoSubdireccion-26'})
r = apoyo.get(BASE + '/carga_masiva_planeaciones.php')
check(r.status_code == 200 and 'Carga masiva' in r.text, 'apoyo a Subdirección sí puede cargar')
check(apoyo.get(BASE + '/activar_cuenta.php').status_code == 403, 'apoyo no administra cuentas')

print('7. Carga masiva: validación')
r = sub.get(BASE + '/carga_masiva_planeaciones.php')
pdfs = {f'materia_{i}.pdf': make_pdf(f'Planeacion semestral materia {i}') for i in range(1, 4)}
files = [('pdfs[]', (f'Grupo101/{n}', c, 'application/pdf')) for n, c in pdfs.items()]
files.append(('pdfs[]', ('Grupo101/desktop.ini', b'[.ShellClassInfo]', 'text/plain')))
files.append(('pdfs[]', ('Grupo101/falso.pdf', b'esto no es un pdf', 'application/pdf')))
data = {'csrf_token': csrf(r.text), 'periodo_id': '1', 'grupo_id': '2', 'tipo_documento': 'SEMESTRAL', 'parcial_id': ''}
r2 = sub.post(BASE + '/carga_masiva_planeaciones.php', data=data, files=files)
check('falso.pdf» no es un PDF válido' in r2.text, 'detecta PDF falso')
files = files[:-1]
r2 = sub.post(BASE + '/carga_masiva_planeaciones.php', data=data, files=files)
check('Validación del lote' in r2.text and '3 PDF' in r2.text, 'lote válido pasa a confirmación (3 PDF)')
check('1 archivo(s) que no eran PDF se omitieron' in r2.text, 'omite desktop.ini con aviso')
opts = re.findall(r'<option value="(\d+)" >([^<]+)</option>', r2.text) or re.findall(r'<option value="(\d+)">([^<]+)</option>', r2.text)
asig_ids = list(dict.fromkeys(o[0] for o in opts))
check(len(asig_ids) == 11, f'muestra solo las 11 asignaciones del grupo 101 en el periodo (hay {len(asig_ids)})')
ING = next(a for a, t in opts if t.startswith('INGLÉS I'))
ANAYA = next(a for a, t in opts if 'ANAYA' in t)

print('8. Carga masiva: confirmación')
r3 = sub.post(BASE + '/confirmar_carga_masiva.php', data={'csrf_token': csrf(r2.text), 'accion': 'confirmar', 'asignacion[0]': asig_ids[0], 'asignacion[1]': asig_ids[0], 'asignacion[2]': asig_ids[2]})
check('misma asignatura' in r3.text, 'impide asignar dos archivos a la misma asignatura')
r3 = sub.post(BASE + '/confirmar_carga_masiva.php', data={'csrf_token': csrf(r2.text), 'accion': 'confirmar', 'asignacion[0]': ING, 'asignacion[1]': ANAYA, 'asignacion[2]': asig_ids[0]})
check('Lote registrado correctamente: 3 PDF' in r3.text, 'registra el lote')

print('9. Consulta y descarga')
r = sub.get(BASE + '/planeaciones.php')
ids = re.findall(r'ver_planeacion\.php\?id=(\d+)', r.text)
check(len(ids) == 3, 'lista 3 planeaciones')
r = sub.get(BASE + '/planeaciones.php', params={'buscar': 'ingles'})
check('INGLÉS I' in r.text and 'Planeaciones encontradas' in r.text, 'búsqueda sin acentos encuentra "INGLÉS"')
pdf = sub.get(BASE + f'/ver_planeacion.php?id={ids[0]}')
check(pdf.headers['Content-Type'] == 'application/pdf' and pdf.content[:5] == b'%PDF-', 'descarga el PDF desde la base')
check(hashlib.sha256(pdf.content).hexdigest() in {hashlib.sha256(c).hexdigest() for c in pdfs.values()}, 'el PDF descargado es idéntico al original')
r = sub.get(BASE + '/planeaciones.php?vista=entregas&grupo=2')
check('Seguimiento de entregas' in r.text and r.text.count('title="Entregada"') == 3, 'seguimiento de entregas marca 3 entregadas')

print('10. Duplicados y versiones')
r = sub.get(BASE + '/carga_masiva_planeaciones.php')
files = [('pdfs[]', ('materia_1.pdf', pdfs['materia_1.pdf'], 'application/pdf'))]
r2 = sub.post(BASE + '/carga_masiva_planeaciones.php', data={**data, 'csrf_token': csrf(r.text)}, files=files)
check('Ya registrado' in r2.text, 'detecta PDF ya registrado')
r3 = sub.post(BASE + '/confirmar_carga_masiva.php', data={'csrf_token': csrf(r2.text), 'accion': 'confirmar', 'asignacion[0]': ING})
check('ya está registrado' in r3.text, 'no permite registrar el duplicado')
sub.post(BASE + '/confirmar_carga_masiva.php', data={'csrf_token': csrf(r2.text), 'accion': 'cancelar'})
r = sub.get(BASE + '/carga_masiva_planeaciones.php')
files = [('pdfs[]', ('materia_1_v2.pdf', make_pdf('Version corregida'), 'application/pdf'))]
r2 = sub.post(BASE + '/carga_masiva_planeaciones.php', data={**data, 'csrf_token': csrf(r.text)}, files=files)
check('reemplaza la versión vigente' in r2.text, 'avisa que reemplazará la versión vigente')
r3 = sub.post(BASE + '/confirmar_carga_masiva.php', data={'csrf_token': csrf(r2.text), 'accion': 'confirmar', 'asignacion[0]': ING})
check('reemplazaron una versión anterior' in r3.text, 'registra nueva versión')
r = sub.get(BASE + '/planeaciones.php')
check(len(re.findall(r'ver_planeacion\.php\?id=', r.text)) == 3, 'solo 3 vigentes en el listado')
r = sub.get(BASE + '/planeaciones.php?historial=1')
check(len(re.findall(r'ver_planeacion\.php\?id=', r.text)) == 4 and 'Versión anterior' in r.text, 'historial muestra 4 (1 anterior)')

print('11. Carga por parcial')
r = sub.get(BASE + '/carga_masiva_planeaciones.php')
r2 = sub.post(BASE + '/carga_masiva_planeaciones.php', data={**data, 'csrf_token': csrf(r.text), 'tipo_documento': 'PARCIAL', 'parcial_id': ''}, files=[('pdfs[]', ('p1.pdf', make_pdf('Parcial 1'), 'application/pdf'))])
check('Selecciona el parcial' in r2.text, 'exige parcial')
r2 = sub.post(BASE + '/carga_masiva_planeaciones.php', data={**data, 'csrf_token': csrf(r.text), 'tipo_documento': 'PARCIAL', 'parcial_id': '2'}, files=[('pdfs[]', ('p2.pdf', make_pdf('Parcial 2 Ingles'), 'application/pdf'))])
check('Parcial 2' in r2.text, 'lote de parcial 2 en validación')
r3 = sub.post(BASE + '/confirmar_carga_masiva.php', data={'csrf_token': csrf(r2.text), 'accion': 'confirmar', 'asignacion[0]': ING})
check('Lote registrado' in r3.text, 'registra parcial 2')

print('12. Visibilidad del docente')
r = doc.get(BASE + '/planeaciones.php')
mine = re.findall(r'ver_planeacion\.php\?id=(\d+)', r.text)
check(len(mine) == 1 and 'EDUCACIÓN PARA LA SALUD' in r.text and 'INGLÉS' not in r.text, 'docente solo ve las suyas')
all_ids = set(re.findall(r'ver_planeacion\.php\?id=(\d+)', sub.get(BASE + '/planeaciones.php?historial=1').text))
others = sorted(all_ids - set(mine))
check(doc.get(BASE + f'/ver_planeacion.php?id={others[0]}').status_code == 403, 'docente no puede abrir PDF ajeno')
for i in mine:
    check(doc.get(BASE + f'/ver_planeacion.php?id={i}').status_code == 200, f'docente abre su PDF {i}')

print('13. Estructura académica, CSRF y salida')
r = doc.get(BASE + '/estructura_academica.php')
check('Grupos registrados' in r.text and '501' in r.text, 'estructura académica carga')
r = sub.post(BASE + '/logout.php', data={'csrf_token': 'x' * 64})
check(r.status_code == 419, 'logout sin CSRF válido es rechazado')
r = sub.get(BASE + '/index.php')
sub.post(BASE + '/logout.php', data={'csrf_token': csrf(r.text)})
check(sub.get(BASE + '/index.php', allow_redirects=False).status_code == 302, 'cierra sesión')

print('14. Suspensión y límite de intentos')
login(sub, 'acceso033', 'Subdireccion-2026!')
r = sub.get(BASE + '/activar_cuenta.php?ver=activas')
r2 = sub.post(BASE + '/activar_cuenta.php', data={'csrf_token': csrf(r.text), 'usuario_id': uid_of(r.text, 'acceso002'), 'accion': 'suspender'})
check('suspendida' in r2.text, 'suspende cuenta del docente')
check(doc.get(BASE + '/index.php', allow_redirects=False).status_code == 302, 'la sesión del docente suspendido deja de ser válida')
t = session()
for _ in range(5):
    login(t, 'acceso017', 'mala-clave-xx')
r = login(t, 'acceso017', 'ApoyoSubdireccion-26')
check('Demasiados intentos' in r.text, 'bloquea tras 5 intentos fallidos')

print(f'\nTodas las pruebas pasaron ({ok_count} verificaciones).')
