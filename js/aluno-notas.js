(function () {
  "use strict";

  function lerDadosNotas() {
    var el = document.getElementById("alunoNotasData");
    if (!el) {
      return null;
    }

    try {
      return JSON.parse(el.textContent || "{}");
    } catch (error) {
      return null;
    }
  }

  function formatarNota(valor) {
    return Number(valor || 0).toFixed(2).replace(".", ",");
  }

  function initMedia(dados) {
    var btnCalcularMedia = document.getElementById("btnCalcularMedia");
    var mediaNotasResultado = document.getElementById("mediaNotasResultado");

    if (!btnCalcularMedia || !mediaNotasResultado || !dados || !Array.isArray(dados.notas)) {
      return;
    }

    if (btnCalcularMedia.dataset.mediaToggleBound === "1") {
      return;
    }
    btnCalcularMedia.dataset.mediaToggleBound = "1";

    var mediaVisivel = false;

    btnCalcularMedia.addEventListener("click", function () {
      if (mediaVisivel) {
        mediaNotasResultado.style.display = "none";
        mediaNotasResultado.textContent = "";
        mediaVisivel = false;
        return;
      }

      if (!dados.notas.length) {
        mediaNotasResultado.style.display = "block";
        mediaNotasResultado.textContent = "Sem notas para calcular media.";
        mediaVisivel = true;
        return;
      }

      var soma = dados.notas.reduce(function (total, item) {
        return total + Number(item.nota || 0);
      }, 0);

      var media = soma / dados.notas.length;
      mediaNotasResultado.style.display = "block";
      mediaNotasResultado.textContent = "Media atual: " + formatarNota(media) + " valores";
      mediaVisivel = true;
    });
  }

  function initPdf(dados) {
    var btnExportarPdf = document.getElementById("btnExportarPdf");
    if (!btnExportarPdf || !dados || !Array.isArray(dados.notas)) {
      return;
    }

    btnExportarPdf.addEventListener("click", function () {
      if (!window.jspdf || !window.jspdf.jsPDF) {
        alert("Nao foi possivel carregar a biblioteca de PDF.");
        return;
      }

      var doc = new window.jspdf.jsPDF();
      var nomeAluno = dados.aluno || "aluno";
      var cursoAluno = dados.curso || "-";

      var linhas = dados.notas.map(function (item) {
        return [
          item.disciplina || "-",
          formatarNota(item.nota),
          item.anoLetivo || "-",
          item.epoca || "-",
          item.dataLancamento || "-",
          item.situacao || "-"
        ];
      });

      doc.setFontSize(14);
      doc.text("Notas do Aluno", 14, 16);
      doc.setFontSize(10);
      doc.text("Aluno: " + nomeAluno, 14, 24);
      doc.text("Curso: " + cursoAluno, 14, 30);

      doc.autoTable({
        startY: 36,
        head: [["Unidade Curricular", "Nota", "Ano letivo", "Epoca", "Data de Lancamento", "Situacao"]],
        body: linhas,
        styles: { fontSize: 10 }
      });

      var nomeSeguro = nomeAluno.replace(/[^a-zA-Z0-9_-]/g, "_");
      doc.save("notas_" + nomeSeguro + ".pdf");
    });
  }

  function init() {
    var dados = lerDadosNotas();
    if (!dados) {
      return;
    }

    initMedia(dados);
    initPdf(dados);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
