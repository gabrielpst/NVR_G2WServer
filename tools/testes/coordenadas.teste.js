// node tools/testes/coordenadas.teste.js — testa o leitor de coordenadas do diálogo de localização
const C = require('../../www/template/dist/js/coordenadas.js');
const casos = [
  ['-20,404751 ; -54,586690', -20.404751, -54.58669],
  ['-20.404751, -54.586690', -20.404751, -54.58669],
  ['-20.404751 -54.586690', -20.404751, -54.58669],
  ['-20.404751,-54.586690', -20.404751, -54.58669],
  ['-20,404751,-54,586690', -20.404751, -54.58669],
  ['−20.404751, −54.586690', -20.404751, -54.58669],
  ['20°24\'17.1"S 54°35\'12.1"W', -20.40475, -54.58669],
  ['https://www.google.com/maps/place/Rua+X/@-20.4047511,-54.5866901,17z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d-20.404751!4d-54.586690', -20.404751, -54.58669],
  ['https://www.google.com/maps/@-20.404751,-54.586690,18z', -20.404751, -54.58669],
  ['https://maps.google.com/?q=-20.404751,-54.586690', -20.404751, -54.58669],
  ['https://www.google.com/maps/search/?api=1&query=-20.404751%2C-54.586690', -20.404751, -54.58669],
  ['2.8235, -60.6758', 2.8235, -60.6758],                // Boa Vista: norte do Equador, ainda Brasil
  ['20.404751, -54.586690', 'sugestao'],                 // latitude sem o menos
  ['-20.404751, 54.586690', 'sugestao'],                 // longitude sem o menos
  ['20.404751, 54.586690', 'sugestao'],
  ['-54.586690, -20.404751', 'sugestao'],                // trocadas
  ['https://maps.app.goo.gl/AbCdEf', 'erro'],
  ['91, 10', 'erro'], ['abc', 'erro'], ['20,54', 'erro'], ['', 'erro'], ['1;2;3', 'erro'],
  ['48.8584, 2.2945', 'aviso'],                          // fora do Brasil: marca, mas avisa
  ['<img src=x onerror=alert(1)>', 'erro'],
];
let falhas = 0;
for (const [t, a, b] of casos) {
  const r = C.ler(t);
  let ok;
  if (a === 'sugestao') ok = !r.ok && !!r.sugestao && C.noBrasil(r.sugestao.lat, r.sugestao.lon);
  else if (a === 'erro') ok = !r.ok && !r.sugestao;
  else if (a === 'aviso') ok = r.ok && !!r.aviso;
  else ok = r.ok && Math.abs(r.lat - a) < 1e-5 && Math.abs(r.lon - b) < 1e-5 && !r.aviso;
  if (!ok) { falhas++; console.log('FALHOU', JSON.stringify(t), JSON.stringify(r)); }
}
console.log((casos.length - falhas) + '/' + casos.length + ' ok');
process.exit(falhas ? 1 : 0);
