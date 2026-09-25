"""Local-only TLS fixture; prints connection metadata, never application credentials."""
import json
import socket
import ssl
import sys

context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(sys.argv[1], sys.argv[2])
sni = []
context.set_servername_callback(lambda sock, name, ctx: sni.append(name))
address = sys.argv[5] if len(sys.argv) > 5 else '127.0.0.1'
mode = sys.argv[6] if len(sys.argv) > 6 else 'normal'
server = socket.socket(socket.AF_INET6 if ':' in address else socket.AF_INET)
server.bind((address, 0))
server.listen(4)
server.settimeout(0.3 if mode == 'observe' else 5)
print(json.dumps({'port': server.getsockname()[1]}), flush=True)
for _ in range(int(sys.argv[4])):
    try:
        raw, _ = server.accept()
    except socket.timeout:
        print(json.dumps({'idle': True}), flush=True)
        break
    print(json.dumps({'accepted': True}), flush=True)
    try:
        with context.wrap_socket(raw, server_side=True) as stream:
            stream.settimeout(3)
            data = b''
            while b'\r\n\r\n' not in data:
                chunk = stream.recv(4096)
                if not chunk:
                    raise ssl.SSLError('client closed before HTTP')
                data += chunk
            headers, body = data.split(b'\r\n\r\n', 1)
            lines = headers.decode('ascii').split('\r\n')
            fields = dict(line.split(': ', 1) for line in lines[1:])
            remaining = int(fields.get('Content-Length', '0')) - len(body)
            while remaining > 0:
                remaining -= len(stream.recv(min(remaining, 4096)))
            print(json.dumps({'host': fields.get('Host'), 'sni': sni[-1], 'request': lines[0]}), flush=True)
            status = sys.argv[3]
            body = b'sensitive-response-body' * 8192 if mode == 'large' else b''
            stream.sendall(('HTTP/1.1 ' + status + ' Test\r\nLocation: https://localhost/blocked\r\nContent-Length: ' + str(len(body)) + '\r\nConnection: keep-alive\r\n\r\n').encode())
            if body:
                stream.sendall(body)
            # Client must close even though this response offers keep-alive.
            print(json.dumps({'closed': stream.recv(1) == b''}), flush=True)
    except (ssl.SSLError, ConnectionResetError, BrokenPipeError):
        print(json.dumps({'tls_rejected': True}), flush=True)
server.close()
