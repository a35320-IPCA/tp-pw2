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

  function ensurePdfLib() {
    return !!(window.jspdf && window.jspdf.jsPDF);
  }

  function formatarNota(valor) {
    return Number(valor || 0).toFixed(2).replace(".", ",");
  }

  function valorOuTraco(valor) {
    var texto = String(valor || "").trim();
    return texto !== "" ? texto : "-";
  }

  function exportarLinhaNota(item) {
    if (!ensurePdfLib()) {
      alert("Nao foi possivel carregar a biblioteca de PDF.");
      return;
    }

    var doc = new window.jspdf.jsPDF();
    var y = 16;

    doc.setFontSize(16);
    doc.text("Registo de Nota", 14, y);
    y += 10;

    doc.setFontSize(11);
    doc.text("ID Nota: " + valorOuTraco(item.id_nota), 14, y);
    y += 7;
    doc.text("ID Aluno: " + valorOuTraco(item.id_aluno), 14, y);
    y += 7;
    doc.text("Aluno: " + valorOuTraco(item.aluno), 14, y);
    y += 7;
    doc.text("Curso: " + valorOuTraco(item.curso), 14, y);
    y += 7;
    doc.text("UC: " + valorOuTraco(item.disciplina), 14, y);
    y += 7;
    doc.text("Nota: " + formatarNota(item.nota), 14, y);
    y += 7;
    doc.text("Ano letivo: " + valorOuTraco(item.ano_letivo), 14, y);
    y += 7;
    doc.text("Epoca: " + valorOuTraco(item.epoca), 14, y);
    y += 7;
    doc.text("Data de lancamento: " + valorOuTraco(item.data_lancamento), 14, y);
    y += 7;
    doc.text("Situacao: " + valorOuTraco(item.situacao), 14, y);

    var nomeSeguro = valorOuTraco(item.aluno).replace(/[^a-zA-Z0-9_-]/g, "_");
    doc.save("nota_" + nomeSeguro + "_" + String(item.id_nota || "").replace(/[^0-9]/g, "") + ".pdf");
  }

  function exportarPauta(dados) {
    if (!ensurePdfLib()) {
      alert("Nao foi possivel carregar a biblioteca de PDF.");
      return;
    }
    if (!Array.isArray(dados.rows) || !dados.rows.length) {
      alert("Nao existem notas para exportar nesta pauta.");
      return;
    }

    var doc = new window.jspdf.jsPDF("l", "mm", "a4");
    doc.setFontSize(14);
    doc.text("Pauta de Avaliacao", 14, 14);

    var subtitulo =
      "Curso: " + valorOuTraco(dados.curso_id) +
      " | Aluno: " + (dados.aluno_id ? String(dados.aluno_id) : "Todos") +
      " | UC: " + (dados.disciplina_id ? String(dados.disciplina_id) : "Todas") +
      " | Ano: " + valorOuTraco(dados.ano_letivo || "Todos") +
      " | Epoca: " + valorOuTraco(dados.epoca || "Todas");

    doc.setFontSize(9);
    doc.text(subtitulo, 14, 20);

    if (!doc.autoTable) {
      alert("A extensao de tabela PDF nao foi carregada.");
      return;
    }

    var linhas = dados.rows.map(function (item) {
      return [
        valorOuTraco(item.id_aluno),
        valorOuTraco(item.aluno),
        valorOuTraco(item.curso),
        valorOuTraco(item.disciplina),
        formatarNota(item.nota),
        valorOuTraco(item.ano_letivo),
        valorOuTraco(item.epoca),
        valorOuTraco(item.data_lancamento),
        valorOuTraco(item.situacao)
      ];
    });

    doc.autoTable({
      startY: 26,
      head: [["ID Aluno", "Aluno", "Curso", "UC", "Nota", "Ano letivo", "Epoca", "Data", "Situacao"]],
      body: linhas,
      styles: { fontSize: 8 }
    });

    doc.save("pauta_notas.pdf");
  }

  function init() {
    var dados = parseJsonScript("notasPautaData");
    if (!dados || !Array.isArray(dados.rows)) {
      return;
    }

    var btnPauta = document.getElementById("btnExportarPautaPdf");
    if (btnPauta) {
      btnPauta.addEventListener("click", function () {
        exportarPauta(dados);
      });
    }

    var botoesLinha = document.querySelectorAll(".btnExportarNotaLinha");
    Array.prototype.forEach.call(botoesLinha, function (btn) {
      btn.addEventListener("click", function () {
        var idNota = String(btn.getAttribute("data-id-nota") || "");
        var item = dados.rows.find(function (row) {
          return String(row.id_nota) === idNota;
        });

        if (!item) {
          alert("Nao foi possivel localizar a nota selecionada.");
          return;
        }

        exportarLinhaNota(item);
      });
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
