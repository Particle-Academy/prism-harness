---
name: prism-labs-remotion-benchmark
description: Execution and proof contract layered onto the vendored Remotion skill.
version: 1
---
# Prism Labs Remotion benchmark contract

This file supplements `SKILL.md`, which is a complete independent copy of the Remotion skill available to the benchmark team.

- A composition is not a working artifact until `remotion_render` succeeds and the output video exists in the lane workspace.
- For 30 seconds at 30 fps, declare exactly 900 frames.
- Use only the dependencies supplied by the pinned lane renderer: `react`, `react-dom`, and `remotion`.
- Call `remotion_render` with the entry file, composition id, and a relative `.mp4` output path.
- The tool verifies the media with `ffprobe` and returns duration, dimensions, codec, byte size, and SHA-256.
- Put that result into a `remotion.render` proof receipt.
- Read `.plabs/remotion-render.json`, confirm the duration is within the specification tolerance, and use the video path as `working_artifact` in `PROOF_OF_WORKING.json`.
- Never claim success from source files alone.
