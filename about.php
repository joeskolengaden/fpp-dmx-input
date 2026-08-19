<div style="margin:0 auto;"> <br />
  <fieldset id="aboutBox" style="padding: 10px; border: 2px solid #000;">
    <legend>DMX Input Plugin Info</legend>
    <div style="overflow: hidden; padding: 10px;">
      <p>
      Adds DMX-512 receive to FPP 5.x, which has no native "Channel Input"
      feature (that shipped later, in FPP 8.2). Reads a UART wired as an
      RS-485 receiver and feeds the decoded universe into FPP's live
      channel data via <code>Sequence::SetBridgeData()</code> - the same
      mechanism the built-in E1.31/ArtNet/DDP bridges use.
      <p>
      Supports up to two simultaneous inputs (one per physical DMX port),
      with per-input enable, start channel, channel count, expiry, and a
      live status page showing packets/bytes/errors received.
      <p>
      <b>Wiring:</b> a UART already used for a DMX output can't also be
      opened here for input - disable that port's DMX-Open output first,
      and set its DE/RE jumper to GND so the transceiver stays in listen
      mode.
      <p>
      <a href='https://github.com/joeskolengaden/fpp-dmx-input'>Git Repository</a><br>
      <a href='https://github.com/joeskolengaden/fpp-dmx-input/issues'>Bug Reporter</a><br>
    </div>
  </fieldset>
</div>
