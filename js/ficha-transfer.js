(function () {
  "use strict";

  function parseJsonScript(id) {
    var el = document.getElementById(id);
    if (!el) {
      return null;
    }
    try {
      return JSON.parse(el.textContent || "{}");
    } catch (error) {
      return null;
    }
  }

  function valorOuTraco(valor) {
    var texto = String(valor || "").trim();
    return texto !== "" ? texto : "-";
  }

  function ensurePdfLib() {
    return !!(window.jspdf && window.jspdf.jsPDF);
  }

  function formatarDataAtual() {
    var agora = new Date();
    var ano = String(agora.getFullYear());
    var mes = String(agora.getMonth() + 1).padStart(2, "0");
    var dia = String(agora.getDate()).padStart(2, "0");
    var hora = String(agora.getHours()).padStart(2, "0");
    var minuto = String(agora.getMinutes()).padStart(2, "0");
    return dia + "/" + mes + "/" + ano + " " + hora + ":" + minuto;
  }

  function detectarMimeImagemPorBytes(bytes) {
    if (!bytes || bytes.length < 12) {
      return null;
    }

    if (bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) {
      return "image/jpeg";
    }
    if (bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4e && bytes[3] === 0x47) {
      return "image/png";
    }
    if (bytes[0] === 0x47 && bytes[1] === 0x49 && bytes[2] === 0x46 && bytes[3] === 0x38) {
      return "image/gif";
    }
    if (
      bytes[0] === 0x52 && bytes[1] === 0x49 && bytes[2] === 0x46 && bytes[3] === 0x46 &&
      bytes[8] === 0x57 && bytes[9] === 0x45 && bytes[10] === 0x42 && bytes[11] === 0x50
    ) {
      return "image/webp";
    }

    return null;
  }

  function carregarImagemComoDataUrl(urlImagem) {
    if (!urlImagem) {
      return Promise.resolve(null);
    }

    var separador = urlImagem.indexOf("?") >= 0 ? "&" : "?";
    var urlComCacheBust = urlImagem + separador + "_ts=" + Date.now();

    return fetch(urlComCacheBust, { credentials: "same-origin", cache: "no-store" })
      .then(function (resposta) {
        if (!resposta.ok) {
          return null;
        }
        return Promise.all([
          resposta.arrayBuffer(),
          Promise.resolve(String(resposta.headers.get("content-type") || "").toLowerCase())
        ]);
      })
      .then(function (dadosResposta) {
        if (!dadosResposta) {
          return null;
        }
        var arrayBuffer = dadosResposta[0];
        var contentType = dadosResposta[1];
        if (!arrayBuffer || arrayBuffer.byteLength === 0) {
          return null;
        }

        var bytes = new Uint8Array(arrayBuffer);
        var mimeDetectado = detectarMimeImagemPorBytes(bytes);
        var mimeFinal = mimeDetectado || (contentType.indexOf("image/") === 0 ? contentType : "application/octet-stream");
        if (mimeFinal.indexOf("image/") !== 0) {
          return null;
        }

        var blob = new Blob([arrayBuffer], { type: mimeFinal });
        return new Promise(function (resolve) {
          var reader = new FileReader();
          reader.onloadend = function () {
            resolve(String(reader.result || ""));
          };
          reader.onerror = function () {
            resolve(null);
          };
          reader.readAsDataURL(blob);
        });
      })
      .catch(function () {
        return null;
      });
  }

  function converterDataUrlParaJpeg(dataUrl) {
    if (!dataUrl) {
      return Promise.resolve(null);
    }

    return new Promise(function (resolve) {
      var imagem = new Image();
      imagem.onload = function () {
        try {
          var canvas = document.createElement("canvas");
          canvas.width = imagem.naturalWidth || imagem.width || 1;
          canvas.height = imagem.naturalHeight || imagem.height || 1;
          var ctx = canvas.getContext("2d");
          if (!ctx) {
            resolve(dataUrl);
            return;
          }
          ctx.fillStyle = "#ffffff";
          ctx.fillRect(0, 0, canvas.width, canvas.height);
          ctx.drawImage(imagem, 0, 0);
          resolve(canvas.toDataURL("image/jpeg", 0.92));
        } catch (error) {
          resolve(dataUrl);
        }
      };
      imagem.onerror = function () {
        resolve(dataUrl);
      };
      imagem.src = dataUrl;
    });
  }

  function getStatusVisual(status) {
    var valor = String(status || "").trim().toLowerCase();
    if (valor === "aprovada") {
      return { texto: "Aprovada", fundo: [231, 248, 239], textoCor: [17, 101, 48], borda: [159, 224, 186] };
    }
    if (valor === "rejeitada") {
      return { texto: "Rejeitada", fundo: [253, 232, 232], textoCor: [155, 28, 28], borda: [244, 180, 180] };
    }
    if (valor === "submetida") {
      return { texto: "Submetida", fundo: [232, 239, 255], textoCor: [18, 58, 122], borda: [184, 200, 230] };
    }
    return { texto: valorOuTraco(status), fundo: [241, 245, 251], textoCor: [63, 79, 107], borda: [209, 220, 239] };
  }

  function desenharCampo(doc, rotulo, valor, x, y, largura, altura) {
    doc.setFillColor(255, 255, 255);
    doc.setDrawColor(222, 232, 247);
    doc.roundedRect(x, y, largura, altura, 2, 2, "FD");

    doc.setFont("helvetica", "bold");
    doc.setFontSize(9);
    doc.setTextColor(63, 79, 107);
    doc.text(rotulo, x + 3, y + 5);

    doc.setFont("helvetica", "normal");
    doc.setFontSize(11);
    doc.setTextColor(20, 30, 45);
    var linhas = doc.splitTextToSize(String(valorOuTraco(valor)), largura - 6);
    doc.text(linhas, x + 3, y + 11);
  }

  async function criarPdfFicha(item, nomeFicheiro) {
    if (!ensurePdfLib()) {
      alert("Nao foi possivel carregar a biblioteca de PDF.");
      return;
    }

    var doc = new window.jspdf.jsPDF("p", "mm", "a4");
    var largura = doc.internal.pageSize.getWidth();
    var altura = doc.internal.pageSize.getHeight();
    var corAzul = [14, 53, 120];
    var corAzulEscuro = [9, 38, 89];
    var nomeAluno = valorOuTraco(item.nome);
    var estadoVisual = getStatusVisual(item.status);
    var fotoDataUrl = String(item.foto_data_url || "").trim();
    if (!fotoDataUrl) {
      fotoDataUrl = await carregarImagemComoDataUrl(item.foto_url);
    }
    var fotoJpeg = await converterDataUrlParaJpeg(fotoDataUrl);
    var y = 18;
    var margem = 14;
    var espaco = 4;
    var larguraColuna = (largura - margem * 2 - espaco) / 2;

    doc.setFillColor(244, 248, 255);
    doc.rect(0, 0, largura, altura, "F");

    doc.setFillColor(221, 232, 251);
    doc.rect(0, 0, 5, altura, "F");

    doc.setFillColor(corAzul[0], corAzul[1], corAzul[2]);
    doc.rect(0, 0, largura, 38, "F");
    doc.setFillColor(corAzulEscuro[0], corAzulEscuro[1], corAzulEscuro[2]);
    doc.rect(0, 38, largura, 2, "F");

    doc.setTextColor(255, 255, 255);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(19);
    doc.text("Ficha de Aluno", 14, y);
    doc.setFontSize(10);
    doc.setFont("helvetica", "normal");
    doc.text("IPCA VNF | Gestao Academica", 14, y + 6);
    doc.text("Emitido em: " + formatarDataAtual(), 14, y + 12);
    doc.text("Ref: FA-" + valorOuTraco(item.id_user), 14, y + 18);
    doc.setDrawColor(255, 255, 255);
    doc.setLineWidth(0.5);
    doc.roundedRect(largura - 46, 6, 32, 28, 2, 2, "S");

    if (fotoJpeg) {
      try {
        doc.addImage(fotoJpeg, "JPEG", largura - 45, 7, 30, 26);
      } catch (error) {
        doc.setFont("helvetica", "italic");
        doc.setFontSize(8);
        doc.text("Sem foto", largura - 37, 21);
      }
    } else {
      doc.setFont("helvetica", "italic");
      doc.setFontSize(8);
      doc.text("Sem foto", largura - 37, 21);
    }

    doc.setDrawColor(corAzulEscuro[0], corAzulEscuro[1], corAzulEscuro[2]);
    doc.setLineWidth(0.4);
    doc.line(14, 46, largura - 14, 46);

    y = 54;
    doc.setFillColor(255, 255, 255);
    doc.setDrawColor(222, 232, 247);
    doc.roundedRect(12, 50, largura - 24, 18, 3, 3, "FD");

    doc.setTextColor(17, 47, 102);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(12);
    doc.text("Estado da Ficha", 16, y + 4);

    doc.setFillColor(estadoVisual.fundo[0], estadoVisual.fundo[1], estadoVisual.fundo[2]);
    doc.setDrawColor(estadoVisual.borda[0], estadoVisual.borda[1], estadoVisual.borda[2]);
    doc.roundedRect(largura - 66, 54, 48, 9, 4, 4, "FD");
    doc.setTextColor(estadoVisual.textoCor[0], estadoVisual.textoCor[1], estadoVisual.textoCor[2]);
    doc.setFontSize(10);
    doc.text(estadoVisual.texto, largura - 57, 60);

    y = 74;

    doc.setTextColor(17, 47, 102);
    doc.setFont("helvetica", "bold");
    doc.setFontSize(12);
    doc.text("Dados do Aluno", margem, y - 4);

    desenharCampo(doc, "Nome", nomeAluno, margem, y, larguraColuna, 18);
    desenharCampo(doc, "ID Aluno", valorOuTraco(item.id_user), margem + larguraColuna + espaco, y, larguraColuna, 18);

    y += 22;
    desenharCampo(doc, "Data de Nascimento", valorOuTraco(item.data_nascimento), margem, y, larguraColuna, 18);
    desenharCampo(doc, "Idade", valorOuTraco(item.idade), margem + larguraColuna + espaco, y, larguraColuna, 18);

    y += 22;
    desenharCampo(doc, "Telefone", valorOuTraco(item.telefone), margem, y, larguraColuna, 18);
    desenharCampo(doc, "NIF", valorOuTraco(item.nif), margem + larguraColuna + espaco, y, larguraColuna, 18);

    y += 22;
    desenharCampo(doc, "Morada", valorOuTraco(item.morada), margem, y, largura - margem * 2, 24);

    doc.setDrawColor(224, 232, 243);
    doc.setLineWidth(0.2);
    doc.line(14, altura - 20, largura - 14, altura - 20);

    doc.setTextColor(92, 107, 130);
    doc.setFont("helvetica", "italic");
    doc.setFontSize(9);
    doc.text("Documento gerado automaticamente pela plataforma IPCA.", 14, altura - 14);

    doc.save(nomeFicheiro);
  }

  function initAluno() {
    var dados = parseJsonScript("fichaAlunoData");
    var btn = document.getElementById("btnExportarFichaAluno");
    if (!dados || !btn) {
      return;
    }

    btn.addEventListener("click", async function () {
      var nomeSeguro = valorOuTraco(dados.nome).replace(/[^a-zA-Z0-9_-]/g, "_");
      await criarPdfFicha(dados, "ficha_aluno_" + nomeSeguro + ".pdf");
    });
  }

  function initStaff() {
    var dados = parseJsonScript("fichasTableData");
    if (!dados || !Array.isArray(dados.fichas)) {
      return;
    }

    var botoesLinha = document.querySelectorAll(".btnExportarFichaLinha");
    Array.prototype.forEach.call(botoesLinha, function (btn) {
      btn.addEventListener("click", async function () {
        var id = String(btn.getAttribute("data-id-user") || "");
        var ficha = dados.fichas.find(function (item) {
          return String(item.id_user) === id;
        });

        if (!ficha) {
          alert("Nao foi possivel localizar os dados da ficha.");
          return;
        }

        var nomeSeguro = valorOuTraco(ficha.nome).replace(/[^a-zA-Z0-9_-]/g, "_");
        await criarPdfFicha(ficha, "ficha_aluno_" + nomeSeguro + ".pdf");
      });
    });
  }

  function init() {
    initAluno();
    initStaff();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
